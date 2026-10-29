<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\Dto\E2ee\CommitInputDto;
use App\Dto\E2ee\LeaveRequestInputDto;
use App\E2ee\CanonicalCbor;
use App\Entity\Conversation;
use App\Entity\E2eeDevice;
use App\Entity\E2eeGroupEpoch;
use App\Entity\E2eeLeaveRequest;
use App\Entity\E2eeWelcome;
use App\Entity\User;
use App\Exception\Conversation\NotMemberException;
use App\Exception\E2ee\E2eeCommitIdConflictException;
use App\Exception\E2ee\E2eeDeviceNotFoundException;
use App\Exception\E2ee\E2eeDeviceRevokedException;
use App\Exception\E2ee\E2eeLeaveRequestNotFoundException;
use App\Exception\E2ee\E2eeStaleEpochException;
use App\Exception\E2ee\E2eeWelcomeNotFoundException;
use App\Exception\E2ee\EncryptedConversationRequiredException;
use App\Exception\E2ee\InvalidE2eeGenerationException;
use App\Exception\E2ee\InvalidE2eeLeaveRequestException;
use App\Exception\E2ee\InvalidE2eeWelcomeTargetException;
use App\Repository\E2eeAccountRepository;
use App\Repository\E2eeDeviceRepository;
use App\Repository\E2eeGroupEpochRepository;
use App\Repository\E2eeKeyPackageRepository;
use App\Repository\E2eeLeaveRequestRepository;
use App\Repository\E2eeWelcomeRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Realtime\E2eeRealtimePublisherInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

class GroupDeliveryService extends AbstractDoctrineService implements GroupDeliveryServiceInterface
{
    private const int LEAVE_CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly SecurityContext $security,
        #[Lazy]
        private readonly ConversationServiceInterface $conversations,
        private readonly E2eeAccountRepository $accounts,
        private readonly E2eeDeviceRepository $devices,
        private readonly E2eeKeyPackageRepository $keyPackages,
        private readonly E2eeGroupEpochRepository $epochs,
        private readonly E2eeWelcomeRepository $welcomes,
        private readonly E2eeLeaveRequestRepository $leaveRequests,
        private readonly E2eeRealtimePublisherInterface $publisher,
    ) {
    }

    #[\Override]
    public function submitCommit(Conversation $conversation, CommitInputDto $commit): array
    {
        $me = $this->assertParticipant($conversation);
        \assert(null !== $commit->commitId && null !== $commit->deviceId && null !== $commit->epoch && null !== $commit->generation
            && null !== $commit->commit && null !== $commit->groupInfo);

        try {
            /** @var array{epoch: E2eeGroupEpoch, created: bool, welcomes: list<E2eeWelcome>}|\DomainException $result */
            $result = $this->transactional(fn (): array|\DomainException => $this->applyCommit($conversation, $me, $commit));
        } catch (UniqueConstraintViolationException) {
            throw new E2eeCommitIdConflictException();
        }
        if ($result instanceof \DomainException) {
            throw $result;
        }

        if ($result['created']) {
            $this->publisher->publishMlsCommit($result['epoch']);
            foreach ($result['welcomes'] as $welcome) {
                $this->publisher->publishMlsWelcome($welcome);
            }
        }

        return ['epoch' => $result['epoch'], 'created' => $result['created']];
    }

    /** @return array{epoch: E2eeGroupEpoch, created: bool, welcomes: list<E2eeWelcome>}|\DomainException */
    private function applyCommit(Conversation $conversation, User $me, CommitInputDto $commit): array|\DomainException
    {
        $this->lockForUpdate($conversation);

        $known = $this->epochs->findOneByCommitId((string) $commit->commitId);
        if (null !== $known) {
            return $known->getConversation()->getId() === $conversation->getId()
                ? ['epoch' => $known, 'created' => false, 'welcomes' => []]
                : new E2eeCommitIdConflictException();
        }

        $device = $this->ownDevice((string) $commit->deviceId, $me);
        if (null === $device) {
            return new E2eeDeviceNotFoundException();
        }
        if ($device->isRevoked() || $device->getGeneration() !== $device->getAccount()->getGeneration()) {
            return new E2eeDeviceRevokedException();
        }

        $latest = $this->epochs->findLatest($conversation);
        $currentEpoch = $latest?->getEpoch() ?? 0;
        if ($commit->epoch !== $currentEpoch + 1) {
            return new E2eeStaleEpochException($currentEpoch);
        }

        $leaveRequest = null;
        if (null !== $commit->leaveRequestId) {
            $leaveRequest = $this->leaveRequests->findForConversation($commit->leaveRequestId, $conversation);
            if (null === $leaveRequest) {
                return new E2eeLeaveRequestNotFoundException();
            }
            if ($leaveRequest->isFulfilled()) {
                return new E2eeStaleEpochException($currentEpoch);
            }
        }

        $memberGenerations = $this->memberGenerationsFor($conversation, $latest, $device, (int) $commit->generation);
        if (null === $memberGenerations) {
            return new InvalidE2eeGenerationException();
        }

        $targets = [];
        foreach ($commit->welcomes as $input) {
            $target = null === $input->deviceId ? null : $this->devices->find($input->deviceId);
            if (null === $target || isset($targets[$target->getId()]) || !$this->isActiveParticipantDevice($target, $conversation)) {
                return new InvalidE2eeWelcomeTargetException();
            }
            $targets[$target->getId()] = [$target, (string) $input->data];
        }

        $epoch = new E2eeGroupEpoch(
            $conversation,
            $commit->epoch,
            $commit->generation,
            (string) $commit->commitId,
            $device->getId(),
            (string) $commit->commit,
            (string) $commit->groupInfo,
            $commit->leaveRequestId,
            $memberGenerations,
        );
        $this->persist($epoch);

        $welcomes = [];
        foreach ($targets as [$target, $data]) {
            $welcome = new E2eeWelcome($target, $epoch, $data);
            $this->persist($welcome);
            $welcomes[] = $welcome;
        }

        $leaveRequest?->fulfil();
        $this->flush();

        return ['epoch' => $epoch, 'created' => true, 'welcomes' => $welcomes];
    }

    /** @return array<int|string, int>|null null when the commit's generation is not acceptable */
    private function memberGenerationsFor(Conversation $conversation, ?E2eeGroupEpoch $latest, E2eeDevice $device, int $generation): ?array
    {
        $myId = (string) $device->getAccount()->getUser()->getId();
        if (null === $latest) {
            return 1 === $generation ? $this->currentAccountGenerations($conversation) : null;
        }

        $recorded = $latest->getMemberGenerations()[$myId] ?? 0;
        if ($generation === $latest->getGeneration()) {
            return $device->getGeneration() === $recorded ? $latest->getMemberGenerations() : null;
        }
        if ($generation === $latest->getGeneration() + 1) {
            return $device->getGeneration() > $recorded ? $this->currentAccountGenerations($conversation) : null;
        }

        return null;
    }

    /** @return array<int|string, int> */
    private function currentAccountGenerations(Conversation $conversation): array
    {
        $generations = [];
        foreach ($conversation->getMembers() as $member) {
            $account = $this->accounts->findOneByUser($member->getUser());
            if (null !== $account) {
                $generations[(string) $member->getUser()->getId()] = $account->getGeneration();
            }
        }

        return $generations;
    }

    private function isActiveParticipantDevice(E2eeDevice $device, Conversation $conversation): bool
    {
        $account = $device->getAccount();

        return !$device->isRevoked()
            && $device->getGeneration() === $account->getGeneration()
            && $this->conversations->isMember($conversation, $account->getUser());
    }

    #[\Override]
    public function state(Conversation $conversation): array
    {
        $this->assertParticipant($conversation);
        $latest = $this->epochs->findLatest($conversation);
        $generation = $latest?->getGeneration() ?? 0;

        return [
            'epoch' => $latest?->getEpoch() ?? 0,
            'generation' => $generation,
            'groupInfo' => $latest?->getGroupInfo(),
            'frozenGenerations' => $generation > 1 ? range(1, $generation - 1) : [],
        ];
    }

    #[\Override]
    public function listCommits(Conversation $conversation, int $after): array
    {
        $this->assertParticipant($conversation);

        return $this->epochs->findAfter($conversation, max(0, $after), self::MAX_COMMITS_PER_PAGE);
    }

    #[\Override]
    public function listWelcomes(?string $deviceId, ?string $after = null): array
    {
        $me = $this->currentHuman();
        $cursor = null;
        if (null !== $after) {
            $cursor = $this->welcomes->find($after);
            if (null === $cursor || $cursor->getId() !== $after || $cursor->getDevice()->getAccount()->getUser()->getId() !== $me->getId()) {
                throw new E2eeWelcomeNotFoundException();
            }
        }
        $account = $this->accounts->findOneByUser($me);

        return null === $account ? [] : $this->welcomes->findUndelivered($account, $deviceId, self::MAX_WELCOMES_PER_PAGE, $cursor);
    }

    #[\Override]
    public function acknowledgeWelcome(string $welcomeId): void
    {
        $me = $this->currentHuman();
        $welcome = $this->welcomes->find($welcomeId);
        if (null === $welcome || $welcome->getId() !== $welcomeId || $welcome->getDevice()->getAccount()->getUser()->getId() !== $me->getId()) {
            throw new E2eeWelcomeNotFoundException();
        }

        $welcome->markDelivered();
        $this->flush();
    }

    #[\Override]
    public function recordLeaveRequest(string $deviceId, LeaveRequestInputDto $request): void
    {
        $me = $this->currentHuman();
        $device = $this->ownDevice($deviceId, $me);
        if (null === $device) {
            throw new E2eeDeviceNotFoundException();
        }
        \assert(null !== $request->leaveRequestId && null !== $request->requestedAt && null !== $request->signature);

        /** @var list<Conversation>|\DomainException $pending */
        $pending = $this->transactional(function () use ($device, $request, $me): array|\DomainException {
            $this->lockForUpdate($device->getAccount());
            $this->refresh($device);
            if ($device->isRevoked()) {
                return [];
            }
            if (!$this->isValidLeaveRequest($device, $request) || null !== $this->leaveRequests->findOneBy(['leaveRequestId' => $request->leaveRequestId])) {
                return new InvalidE2eeLeaveRequestException();
            }

            $device->revoke([
                'signerDeviceId' => $device->getId(),
                'revokedAt' => (int) $request->requestedAt,
                'signature' => (string) $request->signature,
                'leaveRequestId' => (string) $request->leaveRequestId,
            ]);
            $this->keyPackages->deleteAllForDevice($device);
            $conversations = $this->conversations->findEncryptedForUser($me);
            foreach ($conversations as $conversation) {
                $this->persist(new E2eeLeaveRequest((string) $request->leaveRequestId, $device, $conversation));
            }
            $this->flush();

            return $conversations;
        });
        if ($pending instanceof \DomainException) {
            throw $pending;
        }

        foreach ($pending as $conversation) {
            $this->publisher->publishMlsLeaveRequest($conversation, $request->leaveRequestId);
        }
    }

    private function isValidLeaveRequest(E2eeDevice $device, LeaveRequestInputDto $request): bool
    {
        if ((int) $request->requestedAt > time() + self::LEAVE_CLOCK_SKEW_SECONDS) {
            return false;
        }

        $payload = CanonicalCbor::encodeMap([
            'deviceId' => $device->getId(),
            'generation' => $device->getGeneration(),
            'requestedAt' => (int) $request->requestedAt,
            'leaveRequestId' => (string) $request->leaveRequestId,
        ]);
        $signature = base64_decode((string) $request->signature, true);
        $publicKey = base64_decode($device->getPublicKey(), true);
        if (false === $signature || false === $publicKey
            || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature)
            || \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($publicKey)
        ) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
    }

    #[\Override]
    public function listLeaveRequests(Conversation $conversation): array
    {
        $this->assertParticipant($conversation);

        return $this->leaveRequests->findPending($conversation);
    }

    #[\Override]
    public function isGenerationFrozen(Conversation $conversation, int $generation): bool
    {
        $latest = $this->epochs->findLatest($conversation);

        return null !== $latest && $generation < $latest->getGeneration();
    }

    private function assertParticipant(Conversation $conversation): User
    {
        $me = $this->currentHuman();
        if (!$conversation->isEncrypted()) {
            throw new EncryptedConversationRequiredException();
        }
        if (!$this->conversations->isMember($conversation, $me)) {
            throw new NotMemberException();
        }

        return $me;
    }

    private function ownDevice(string $deviceId, User $me): ?E2eeDevice
    {
        $device = $this->devices->find($deviceId);

        return null !== $device && $device->getId() === $deviceId && $device->getAccount()->getUser()->getId() === $me->getId() ? $device : null;
    }

    private function currentHuman(): User
    {
        $me = $this->security->currentUser();
        $this->security->throwAccessDeniedIf($me->isBot());

        return $me;
    }
}
