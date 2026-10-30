<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\E2ee\HistoryRecordPayload;
use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\E2eeHistoryRecord;
use App\Entity\User;
use App\Exception\E2ee\E2eeAccountRequiredException;
use App\Exception\E2ee\E2eeDeviceNotFoundException;
use App\Exception\E2ee\E2eeDeviceRevokedException;
use App\Exception\E2ee\E2eeHistoryHeadMismatchException;
use App\Exception\E2ee\E2eeIdentityBackupExistsException;
use App\Exception\E2ee\E2eeIdentityBackupNotFoundException;
use App\Exception\E2ee\E2eeStaleGenerationException;
use App\Exception\E2ee\InvalidE2eeHistorySignatureException;
use App\Repository\E2eeAccountRepository;
use App\Repository\E2eeDeviceRepository;
use App\Repository\E2eeHistoryRecordRepository;
use App\Repository\E2eeKeyPackageRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class RecoveryBackupService extends AbstractDoctrineService implements RecoveryBackupServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly E2eeAccountRepository $accounts,
        private readonly E2eeDeviceRepository $devices,
        private readonly E2eeKeyPackageRepository $keyPackages,
        private readonly E2eeHistoryRecordRepository $history,
        private readonly E2eeServiceInterface $e2ee,
    ) {
    }

    #[\Override]
    public function storeIdentityBackup(string $blob): void
    {
        $account = $this->ownAccount();
        $refusal = $this->transactional(function () use ($account, $blob): ?E2eeIdentityBackupExistsException {
            $this->lockForUpdate($account);
            if (null !== $account->getIdentityBackup()) {
                return new E2eeIdentityBackupExistsException();
            }
            $account->storeIdentityBackup($blob);
            $this->flush();

            return null;
        });
        if (null !== $refusal) {
            throw $refusal;
        }
    }

    #[\Override]
    public function getIdentityBackup(): array
    {
        $account = $this->accounts->findOneByUser($this->me());
        $blob = $account?->getIdentityBackup();
        if (null === $account || null === $blob) {
            throw new E2eeIdentityBackupNotFoundException();
        }

        return ['blob' => $blob, 'generation' => $account->getGeneration()];
    }

    #[\Override]
    public function appendHistoryRecord(string $deviceId, int $counter, string $blob, string $signature): bool
    {
        $account = $this->ownAccount();
        $device = $this->devices->find($deviceId);
        if (null === $device || $device->getId() !== $deviceId || $device->getAccount()->getId() !== $account->getId()) {
            throw new E2eeDeviceNotFoundException();
        }

        try {
            $result = $this->transactional(function () use ($account, $device, $counter, $blob, $signature): bool|\DomainException {
                $this->lockForUpdate($account);
                $this->lockForUpdate($device);
                if ($device->isRevoked() || $device->getGeneration() !== $account->getGeneration()) {
                    return new E2eeDeviceRevokedException();
                }
                if (!$this->isSignedByDevice($device, $counter, $blob, $signature)) {
                    return new InvalidE2eeHistorySignatureException();
                }
                $head = $this->history->head($device);
                if ($counter <= $head) {
                    $existing = $this->history->findOneAt($device, $counter);

                    return null !== $existing && hash_equals($existing->getBlob(), $blob) ? false : new E2eeHistoryHeadMismatchException($head);
                }
                if ($counter !== $head + 1) {
                    return new E2eeHistoryHeadMismatchException($head);
                }
                $this->persist(new E2eeHistoryRecord($device, $counter, $blob));
                $this->flush();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw new E2eeHistoryHeadMismatchException($counter);
        }
        if ($result instanceof \DomainException) {
            throw $result;
        }

        return $result;
    }

    #[\Override]
    public function listHistory(?string $afterDeviceId, int $afterCounter, int $limit): array
    {
        $account = $this->ownAccount();

        return $this->history->findPage($account, $afterDeviceId, $afterCounter, max(1, min($limit, self::MAX_HISTORY_PAGE)));
    }

    #[\Override]
    public function historyHeads(): array
    {
        return $this->history->heads($this->ownAccount());
    }

    #[\Override]
    public function reset(string $newPublicKey, string $identityBackup, int $expectedGeneration): int
    {
        $account = $this->ownAccount();
        $this->e2ee->assertSetupAllowed();

        $result = $this->transactional(function () use ($account, $newPublicKey, $identityBackup, $expectedGeneration): int|E2eeStaleGenerationException {
            $this->lockForUpdate($account);
            if ($account->getGeneration() !== $expectedGeneration) {
                return new E2eeStaleGenerationException($account->getGeneration());
            }
            $account->reset($newPublicKey, $identityBackup);
            foreach ($this->devices->findBy(['account' => $account, 'revokedAt' => null]) as $device) {
                $device->revokeByReset();
                $this->keyPackages->deleteAllForDevice($device);
            }
            $this->history->deleteForAccount($account);
            $this->flush();

            return $account->getGeneration();
        });
        if ($result instanceof E2eeStaleGenerationException) {
            throw $result;
        }

        return $result;
    }

    private function isSignedByDevice(E2eeDevice $device, int $counter, string $blob, string $signature): bool
    {
        $rawSignature = base64_decode($signature, true);
        $publicKey = base64_decode($device->getPublicKey(), true);
        $rawBlob = base64_decode($blob, true);
        if (false === $rawSignature || false === $publicKey || false === $rawBlob
            || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($rawSignature)
            || \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($publicKey)
        ) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $rawSignature,
            HistoryRecordPayload::encode($device->getId(), $device->getGeneration(), $counter, $rawBlob),
            $publicKey,
        );
    }

    private function me(): User
    {
        $me = $this->security->currentUser();
        $this->security->throwAccessDeniedIf($me->isBot());

        return $me;
    }

    private function ownAccount(): E2eeAccount
    {
        $account = $this->accounts->findOneByUser($this->me());
        if (null === $account) {
            throw new E2eeAccountRequiredException();
        }

        return $account;
    }
}
