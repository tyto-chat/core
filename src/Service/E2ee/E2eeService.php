<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\E2ee\ByteString;
use App\E2ee\CanonicalCbor;
use App\E2ee\KeyPackageUploadPayload;
use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\E2eeKeyPackage;
use App\Entity\User;
use App\Enum\Settings\EncryptedDmsMode;
use App\Exception\E2ee\E2eeAccountAlreadyExistsException;
use App\Exception\E2ee\E2eeAccountRequiredException;
use App\Exception\E2ee\E2eeDeviceAlreadyExistsException;
use App\Exception\E2ee\E2eeDeviceLimitException;
use App\Exception\E2ee\E2eeDeviceNotFoundException;
use App\Exception\E2ee\E2eeDeviceRevokedException;
use App\Exception\E2ee\E2eeKeyPackageLimitException;
use App\Exception\E2ee\E2eeLastResortRequiredException;
use App\Exception\E2ee\E2eeStaleLastResortException;
use App\Exception\E2ee\E2eeUserNotReachableException;
use App\Exception\E2ee\EncryptedDmsDisabledException;
use App\Exception\E2ee\InvalidE2eeCredentialException;
use App\Exception\E2ee\InvalidE2eeKeyPackageException;
use App\Exception\E2ee\InvalidE2eeRevocationException;
use App\Exception\E2ee\InvalidE2eeUploadSignatureException;
use App\Exception\User\UserNotFoundException;
use App\Repository\E2eeAccountRepository;
use App\Repository\E2eeDeviceRepository;
use App\Repository\E2eeKeyPackageRepository;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\HttpFoundation\RequestStack;

class E2eeService extends AbstractDoctrineService implements E2eeServiceInterface
{
    private const int REVOCATION_CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly E2eeAccountRepository $accounts,
        private readonly E2eeDeviceRepository $devices,
        private readonly E2eeKeyPackageRepository $keyPackages,
        private readonly UserRepository $users,
        private readonly CommunityMembershipServiceInterface $memberships,
        private readonly RequestStack $requestStack,
        private readonly SettingsServiceInterface $settings,
        #[Lazy]
        private readonly ConversationServiceInterface $conversations,
    ) {
    }

    #[\Override]
    public function registerAccount(string $publicKey): E2eeAccount
    {
        $me = $this->currentHuman();
        $this->assertSetupAllowed();

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
        $account = $this->accounts->findOneByUser($me);
        if (null === $account) {
            $this->assertSetupAllowed();

            throw new E2eeAccountRequiredException();
        }

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

        try {
            $result = $this->transactional(function () use ($account, $id, $name, $publicKey, $server, $credential): E2eeDevice|\DomainException {
                $this->lockForUpdate($account);
                if (null !== $this->devices->find($id)) {
                    return new E2eeDeviceAlreadyExistsException();
                }
                if (\count($this->devices->findActiveByAccount($account)) >= self::MAX_ACTIVE_DEVICES) {
                    return new E2eeDeviceLimitException();
                }

                $device = new E2eeDevice($id, $account, $name, $publicKey, $server, $account->getGeneration(), $credential);
                $this->persist($device);
                $this->flush();

                return $device;
            });
        } catch (UniqueConstraintViolationException) {
            throw new E2eeDeviceAlreadyExistsException();
        }
        if ($result instanceof \DomainException) {
            throw $result;
        }

        return $result;
    }

    #[\Override]
    public function listOwnDevices(): array
    {
        $account = $this->accounts->findOneByUser($this->currentHuman());

        return array_map(fn (E2eeDevice $device): array => [
            'device' => $device,
            'lastResortFingerprint' => $this->keyPackages->findLatestLastResortFingerprint($device),
        ], null === $account ? [] : $this->devices->findActiveByAccount($account));
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
        $refusal = $this->transactional(function () use ($device, $signerDeviceId, $revokedAt, $signature): ?InvalidE2eeRevocationException {
            $account = $device->getAccount();
            $this->lockForUpdate($account);
            $this->refresh($device);
            if ($device->isRevoked()) {
                return null;
            }

            $signer = $signerDeviceId === $device->getId() ? $device : $this->devices->find($signerDeviceId);
            if (null !== $signer && $signer->getId() !== $signerDeviceId) {
                $signer = null;
            }
            if (null !== $signer && $signer !== $device) {
                $this->refresh($signer);
            }
            if (null === $signer || !$this->isValidRevocation($device, $signer, $revokedAt, $signature)) {
                return new InvalidE2eeRevocationException();
            }

            $device->revoke(['signerDeviceId' => $signer->getId(), 'revokedAt' => $revokedAt, 'signature' => $signature]);
            $this->keyPackages->deleteAllForDevice($device);
            $this->flush();

            return null;
        });

        if (null !== $refusal) {
            throw $refusal;
        }
    }

    private function isValidRevocation(E2eeDevice $device, E2eeDevice $signer, int $revokedAt, string $signature): bool
    {
        $account = $device->getAccount();
        if ($signer->getAccount()->getId() !== $account->getId()
            || $signer->isRevoked()
            || $signer->getGeneration() !== $account->getGeneration()
            || $revokedAt > time() + self::REVOCATION_CLOCK_SKEW_SECONDS
        ) {
            return false;
        }

        $payload = CanonicalCbor::encodeMap([
            'deviceId' => $device->getId(),
            'generation' => $device->getGeneration(),
            'revokedAt' => $revokedAt,
            'signerDeviceId' => $signer->getId(),
        ]);

        return $this->verify($signature, $payload, $signer->getPublicKey());
    }

    #[\Override]
    public function storeKeyPackages(string $deviceId, array $packages, ?array $lastResort, ?string $lastResortReplaces, string $signature): void
    {
        $me = $this->currentHuman();
        $device = $this->devices->find($deviceId);
        if (null === $device || $device->getId() !== $deviceId || $device->getAccount()->getUser()->getId() !== $me->getId()) {
            throw new E2eeDeviceNotFoundException();
        }

        $replaces = null === $lastResortReplaces ? null : (string) base64_decode($lastResortReplaces, true);
        if (!$this->verify($signature, KeyPackageUploadPayload::encode($device->getId(), $device->getGeneration(), $packages, $lastResort, $replaces), $device->getPublicKey())) {
            throw new InvalidE2eeUploadSignatureException();
        }

        $now = new \DateTimeImmutable();
        $expiries = [];
        foreach ([...$packages, ...(null === $lastResort ? [] : [$lastResort])] as $package) {
            $expiries[] = $this->assertValidExpiry($package['expiresAt'], $now);
        }

        $refusal = $this->transactional(function () use ($device, $packages, $lastResort, $replaces, $expiries, $now): ?\DomainException {
            $account = $device->getAccount();
            $this->lockForUpdate($account);
            $this->refresh($device);
            if ($device->isRevoked() || $device->getGeneration() !== $account->getGeneration()) {
                return new E2eeDeviceRevokedException();
            }

            $this->keyPackages->deleteExpired($device, $now);
            $seen = array_fill_keys($this->keyPackages->findExistingFingerprints($device, array_map(
                static fn (array $package): string => E2eeKeyPackage::fingerprintOf($package['data']),
                [...$packages, ...(null === $lastResort ? [] : [$lastResort])],
            )), true);

            $new = [];
            foreach ($packages as $index => $package) {
                $fingerprint = E2eeKeyPackage::fingerprintOf($package['data']);
                if (!isset($seen[$fingerprint])) {
                    $seen[$fingerprint] = true;
                    $new[] = new E2eeKeyPackage($device, $package['data'], $expiries[$index]);
                }
            }
            $currentLastResort = $this->keyPackages->findUsableLastResort($device, $now);
            $storesNewLastResort = null !== $lastResort && !isset($seen[E2eeKeyPackage::fingerprintOf($lastResort['data'])]);
            $latestLastResort = $this->keyPackages->findLatestLastResortFingerprint($device);
            if ($storesNewLastResort && null !== $latestLastResort && $latestLastResort !== bin2hex($replaces ?? '')) {
                return new E2eeStaleLastResortException();
            }
            if (!$storesNewLastResort && null === $currentLastResort) {
                return new E2eeLastResortRequiredException();
            }
            if ([] !== $new && $this->keyPackages->countUsableOneTime($device, $now) + \count($new) > self::MAX_ONE_TIME_KEY_PACKAGES) {
                return new E2eeKeyPackageLimitException();
            }
            foreach ($new as $package) {
                $this->persist($package);
            }
            if ($storesNewLastResort) {
                $this->keyPackages->retireLastResort($device, $now);
                $this->persist(new E2eeKeyPackage($device, $lastResort['data'], $expiries[\count($packages)], true));
            }
            $this->flush();

            return null;
        });

        if (null !== $refusal) {
            throw $refusal;
        }
    }

    #[\Override]
    public function claimKeyPackages(int $userId): array
    {
        $user = $this->visibleUser($userId);
        if (null === $this->accounts->findOneByUser($this->currentHuman())) {
            throw new E2eeAccountRequiredException();
        }
        $account = $this->accounts->findOneByUser($user);
        $now = new \DateTimeImmutable();

        /** @var list<array{deviceId: string, keyPackage: string}> $claimed */
        $claimed = null === $account ? [] : $this->transactional(function () use ($account, $now): array {
            $this->lockForUpdate($account);
            $claimed = [];
            foreach ($this->devices->findActiveByAccount($account) as $device) {
                $package = $this->keyPackages->claimOneTime($device, $now) ?? $this->keyPackages->findUsableLastResort($device, $now);
                if (null !== $package) {
                    $claimed[] = ['deviceId' => $device->getId(), 'keyPackage' => $package];
                }
            }

            return $claimed;
        });

        if ([] === $claimed) {
            throw new E2eeUserNotReachableException($user->getProfile()?->getName() ?? '#'.$user->getId());
        }

        return $claimed;
    }

    #[\Override]
    public function isReachable(User $user): bool
    {
        $account = $this->accounts->findOneByUser($user);
        if (null === $account) {
            return false;
        }

        $now = new \DateTimeImmutable();
        foreach ($this->devices->findActiveByAccount($account) as $device) {
            if ($this->keyPackages->hasUsable($device, $now)) {
                return true;
            }
        }

        return false;
    }

    private function assertValidExpiry(int $expiresAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($expiresAt <= $now->getTimestamp() || $expiresAt > $now->getTimestamp() + self::MAX_KEY_PACKAGE_LIFETIME_SECONDS) {
            throw new InvalidE2eeKeyPackageException();
        }

        return (new \DateTimeImmutable())->setTimestamp($expiresAt);
    }

    #[\Override]
    public function mode(): EncryptedDmsMode
    {
        return $this->settings->get(Settings::encryptedDmsMode());
    }

    #[\Override]
    public function assertSetupAllowed(): void
    {
        if (EncryptedDmsMode::Disabled === $this->mode()) {
            throw new EncryptedDmsDisabledException();
        }
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
        if ($user->getId() !== $me->getId()
            && !$this->security->isAdmin()
            && !$this->memberships->existsSharedCommunity($me, $user)
            && !$this->conversations->sharesEncryptedConversation($me, $user)
        ) {
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
