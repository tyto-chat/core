<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\E2ee\ByteString;
use App\E2ee\CanonicalCbor;
use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\User;
use App\Exception\E2ee\E2eeAccountAlreadyExistsException;
use App\Exception\E2ee\E2eeAccountRequiredException;
use App\Exception\E2ee\E2eeDeviceAlreadyExistsException;
use App\Exception\E2ee\E2eeDeviceNotFoundException;
use App\Exception\E2ee\InvalidE2eeCredentialException;
use App\Exception\E2ee\InvalidE2eeRevocationException;
use App\Exception\User\UserNotFoundException;
use App\Repository\E2eeAccountRepository;
use App\Repository\E2eeDeviceRepository;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\RequestStack;

class E2eeService extends AbstractDoctrineService implements E2eeServiceInterface
{
    private const int REVOCATION_CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly E2eeAccountRepository $accounts,
        private readonly E2eeDeviceRepository $devices,
        private readonly UserRepository $users,
        private readonly CommunityMembershipServiceInterface $memberships,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function registerAccount(string $publicKey): E2eeAccount
    {
        $me = $this->currentHuman();

        if (null !== $this->accounts->findOneByUser($me)) {
            throw new E2eeAccountAlreadyExistsException();
        }

        $account = new E2eeAccount($me, $publicKey);
        $this->persist($account);
        try {
            $this->flush();
        } catch (UniqueConstraintViolationException) {
            throw new E2eeAccountAlreadyExistsException();
        }

        return $account;
    }

    #[\Override]
    public function registerDevice(string $id, string $name, string $publicKey, string $server, string $credential): E2eeDevice
    {
        $me = $this->currentHuman();
        $account = $this->accounts->findOneByUser($me) ?? throw new E2eeAccountRequiredException();

        if (!$this->isThisServer($server)) {
            throw new InvalidE2eeCredentialException();
        }

        $payload = CanonicalCbor::encodeMap([
            'server' => $server,
            'accountId' => (int) $me->getId(),
            'deviceId' => $id,
            'generation' => $account->getGeneration(),
            'devicePublicKey' => new ByteString((string) base64_decode($publicKey, true)),
        ]);
        if (!$this->verify($credential, $payload, $account->getPublicKey())) {
            throw new InvalidE2eeCredentialException();
        }

        if (null !== $this->devices->find($id)) {
            throw new E2eeDeviceAlreadyExistsException();
        }

        $device = new E2eeDevice($id, $account, $name, $publicKey, $server, $account->getGeneration(), $credential);
        $this->persist($device);
        try {
            $this->flush();
        } catch (UniqueConstraintViolationException) {
            throw new E2eeDeviceAlreadyExistsException();
        }

        return $device;
    }

    #[\Override]
    public function listOwnDevices(): array
    {
        $account = $this->accounts->findOneByUser($this->currentHuman());

        return null === $account ? [] : $this->devices->findActiveByAccount($account);
    }

    #[\Override]
    public function listDevices(int $userId): array
    {
        $account = $this->accounts->findOneByUser($this->visibleUser($userId));

        return [
            'account' => $account,
            'devices' => null === $account ? [] : $this->devices->findActiveByAccount($account),
        ];
    }

    #[\Override]
    public function listRevocations(int $userId): array
    {
        $account = $this->accounts->findOneByUser($this->visibleUser($userId));
        if (null === $account) {
            return [];
        }

        $byId = [];
        foreach ($this->devices->findBy(['account' => $account]) as $device) {
            $byId[$device->getId()] = $device;
        }

        $rows = [];
        foreach ($this->devices->findRevokedByAccount($account) as $device) {
            $signerId = $device->getRevocation()['signerDeviceId'] ?? null;
            $signer = null === $signerId ? null : ($byId[$signerId] ?? null);
            if (null !== $signer) {
                $rows[] = ['device' => $device, 'signer' => $signer];
            }
        }

        return $rows;
    }

    #[\Override]
    public function revokeDevice(string $deviceId, string $signerDeviceId, int $revokedAt, string $signature): void
    {
        $me = $this->currentHuman();
        $device = $this->devices->find($deviceId);
        if (null === $device || $device->getId() !== $deviceId || $device->getAccount()->getUser()->getId() !== $me->getId()) {
            throw new E2eeDeviceNotFoundException();
        }
        $this->transactional(function () use ($device, $signerDeviceId, $revokedAt, $signature): void {
            $account = $device->getAccount();
            $this->lockForUpdate($account);
            $this->refresh($device);
            if ($device->isRevoked()) {
                return;
            }

            $signer = $signerDeviceId === $device->getId() ? $device : $this->devices->find($signerDeviceId);
            if (null !== $signer && $signer->getId() !== $signerDeviceId) {
                $signer = null;
            }
            if (null !== $signer && $signer !== $device) {
                $this->refresh($signer);
            }
            $this->assertValidRevocation($device, $signer, $revokedAt, $signature);
            \assert(null !== $signer);

            $device->revoke(['signerDeviceId' => $signer->getId(), 'revokedAt' => $revokedAt, 'signature' => $signature]);
            $this->flush();
        });
    }

    private function assertValidRevocation(E2eeDevice $device, ?E2eeDevice $signer, int $revokedAt, string $signature): void
    {
        $account = $device->getAccount();
        if (null === $signer
            || $signer->getAccount()->getId() !== $account->getId()
            || $signer->isRevoked()
            || $signer->getGeneration() !== $account->getGeneration()
            || $revokedAt > time() + self::REVOCATION_CLOCK_SKEW_SECONDS
        ) {
            throw new InvalidE2eeRevocationException();
        }

        $payload = CanonicalCbor::encodeMap([
            'deviceId' => $device->getId(),
            'generation' => $device->getGeneration(),
            'revokedAt' => $revokedAt,
            'signerDeviceId' => $signer->getId(),
        ]);
        if (!$this->verify($signature, $payload, $signer->getPublicKey())) {
            throw new InvalidE2eeRevocationException();
        }
    }

    #[\Override]
    public function isReachable(User $user): bool
    {
        $account = $this->accounts->findOneByUser($user);

        return null !== $account && [] !== $this->devices->findActiveByAccount($account);
    }

    #[\Override]
    public function isAccountEnabled(User $user): bool
    {
        return null !== $this->accounts->findOneByUser($user);
    }

    private function currentHuman(): User
    {
        $me = $this->security->currentUser();
        $this->security->throwAccessDeniedIf($me->isBot());

        return $me;
    }

    private function visibleUser(int $userId): User
    {
        $me = $this->currentHuman();
        $user = $this->users->find($userId);
        if (null === $user || $user->isBot()) {
            throw new UserNotFoundException();
        }
        if ($user->getId() !== $me->getId() && !$this->security->isAdmin() && !$this->memberships->existsSharedCommunity($me, $user)) {
            throw new UserNotFoundException();
        }

        return $user;
    }

    private function isThisServer(string $server): bool
    {
        $request = $this->requestStack->getMainRequest();
        $parts = parse_url($server);
        if (null === $request || false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return false;
        }

        $scheme = $parts['scheme'];
        if (!\in_array($scheme, ['http', 'https'], true) || ($request->isSecure() && 'https' !== $scheme)) {
            return false;
        }

        $port = $parts['port'] ?? null;
        $authority = strtolower($parts['host']).(null === $port || $port === ('https' === $scheme ? 443 : 80) ? '' : ':'.$port);

        return $server === $scheme.'://'.$authority && $authority === strtolower($request->getHttpHost());
    }

    private function verify(string $signatureBase64, string $message, string $publicKeyBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($publicKeyBase64, true);
        if (false === $signature || false === $publicKey
            || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature)
            || \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($publicKey)
        ) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
}
