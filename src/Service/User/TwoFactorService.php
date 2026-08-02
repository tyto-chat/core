<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\TwoFactorRecoveryCodesDto;
use App\Dto\User\TwoFactorSetupDto;
use App\Dto\User\TwoFactorStatusDto;
use App\Entity\RecoveryCode;
use App\Entity\User;
use App\Exception\User\InvalidCurrentPasswordException;
use App\Exception\User\InvalidTwoFactorCodeException;
use App\Exception\User\TwoFactorAlreadyEnabledException;
use App\Exception\User\TwoFactorNotEnabledException;
use App\Exception\User\TwoFactorSetupNotStartedException;
use App\Repository\RecoveryCodeRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Security\SecretBoxInterface;
use App\Service\Security\SessionRevokerInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use OTPHP\TOTP;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TwoFactorService extends AbstractDoctrineService implements TwoFactorServiceInterface
{
    private const int TOTP_LEEWAY_SECONDS = 29;
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly SecretBoxInterface $secretBox,
        private readonly RecoveryCodeRepository $recoveryCodeRepository,
        private readonly SettingsServiceInterface $settings,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionRevokerInterface $sessionRevoker,
    ) {
    }

    #[\Override]
    public function status(): TwoFactorStatusDto
    {
        $user = $this->security->currentUser();
        $enabled = $user->isTwoFactorEnabled();

        return new TwoFactorStatusDto(
            enabled: $enabled,
            enabledAt: $user->getTotpEnabledAt()?->format(\DateTimeInterface::ATOM),
            recoveryCodesRemaining: $enabled ? $this->recoveryCodeRepository->countUnused($user) : 0,
        );
    }

    #[\Override]
    public function setup(): TwoFactorSetupDto
    {
        $user = $this->security->currentUser();
        if ($user->isTwoFactorEnabled()) {
            throw new TwoFactorAlreadyEnabledException('Two-factor authentication is already enabled.');
        }

        $totp = TOTP::generate();
        $totp->setLabel($this->nonEmpty($user->getEmail(), 'user'));
        $totp->setIssuer($this->issuer());

        $user->setTwoFactorSecret($this->secretBox->encrypt($totp->getSecret()));
        $this->save($user);

        return new TwoFactorSetupDto(
            secret: $totp->getSecret(),
            otpauthUri: $totp->getProvisioningUri(),
        );
    }

    #[\Override]
    public function confirm(string $code): TwoFactorRecoveryCodesDto
    {
        $user = $this->security->currentUser();
        if ($user->isTwoFactorEnabled()) {
            throw new TwoFactorAlreadyEnabledException('Two-factor authentication is already enabled.');
        }
        $secret = $this->decryptedSecret($user);
        if (null === $secret || '' === $secret) {
            throw new TwoFactorSetupNotStartedException('Two-factor setup has not been started.');
        }
        $timestep = $this->matchTotpTimestep($secret, $code);
        if (null === $timestep) {
            throw new InvalidTwoFactorCodeException('Invalid two-factor code.');
        }

        $user->setTotpEnabledAt(new \DateTimeImmutable());
        $user->setTotpLastUsedTimestep($timestep);
        $codes = $this->issueRecoveryCodes($user);
        $this->save($user);
        $this->sessionRevoker->revokeRefreshTokens($user);

        return new TwoFactorRecoveryCodesDto($codes);
    }

    #[\Override]
    public function disable(string $currentPassword): void
    {
        $user = $this->requireEnabled();
        $this->assertPassword($user, $currentPassword);

        $this->reset($user);
    }

    #[\Override]
    public function reset(User $user): void
    {
        $user->setTwoFactorSecret(null);
        $user->setTotpEnabledAt(null);
        $user->setTotpLastUsedTimestep(null);
        $this->recoveryCodeRepository->deleteAllForUser($user);
        $this->save($user);
        $this->sessionRevoker->revokeRefreshTokens($user);
    }

    #[\Override]
    public function regenerateRecoveryCodes(string $currentPassword): TwoFactorRecoveryCodesDto
    {
        $user = $this->requireEnabled();
        $this->assertPassword($user, $currentPassword);

        $codes = $this->issueRecoveryCodes($user);
        $this->save($user);

        return new TwoFactorRecoveryCodesDto($codes);
    }

    #[\Override]
    public function verifyLogin(User $user, string $code): void
    {
        $secret = $this->decryptedSecret($user);
        if (null === $secret || '' === $secret || !$user->isTwoFactorEnabled()) {
            throw new InvalidTwoFactorCodeException('Invalid two-factor code.');
        }
        $timestep = $this->matchTotpTimestep($secret, $code);
        if (null !== $timestep) {
            $last = $user->getTotpLastUsedTimestep();
            if (null !== $last && $timestep <= $last) {
                throw new InvalidTwoFactorCodeException('Invalid two-factor code.');
            }
            $user->setTotpLastUsedTimestep($timestep);
            $this->save($user);

            return;
        }
        if ($this->consumeRecoveryCode($user, $code)) {
            return;
        }

        throw new InvalidTwoFactorCodeException('Invalid two-factor code.');
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
        if ('' === $normalized) {
            return false;
        }
        $hash = hash('sha256', $normalized);
        foreach ($this->recoveryCodeRepository->findUnusedByUser($user) as $recoveryCode) {
            if (hash_equals($recoveryCode->getCodeHash(), $hash)) {
                return $this->recoveryCodeRepository->tryConsume($recoveryCode);
            }
        }

        return false;
    }

    private function requireEnabled(): User
    {
        $user = $this->security->currentUser();
        if (!$user->isTwoFactorEnabled()) {
            throw new TwoFactorNotEnabledException('Two-factor authentication is not enabled.');
        }

        return $user;
    }

    private function assertPassword(User $user, string $currentPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new InvalidCurrentPasswordException('Current password is incorrect.');
        }
    }

    /** @return non-empty-string */
    private function issuer(): string
    {
        return $this->nonEmpty($this->settings->get(Settings::serverName()), 'tyto.chat');
    }

    /**
     * @param non-empty-string $fallback
     *
     * @return non-empty-string
     */
    private function nonEmpty(?string $value, string $fallback): string
    {
        return null !== $value && '' !== $value ? $value : $fallback;
    }

    private function decryptedSecret(User $user): ?string
    {
        $encrypted = $user->getTwoFactorSecret();

        return null === $encrypted ? null : $this->secretBox->decrypt($encrypted);
    }

    /** @param non-empty-string $secret */
    private function matchTotpTimestep(string $secret, string $code): ?int
    {
        $normalized = preg_replace('/\D/', '', $code) ?? '';
        if ('' === $normalized) {
            return null;
        }

        $totp = TOTP::createFromSecret($secret);
        $now = time();
        foreach ([0, -self::TOTP_LEEWAY_SECONDS, self::TOTP_LEEWAY_SECONDS] as $offset) {
            $timestamp = max(0, $now + $offset);
            if (hash_equals($totp->at($timestamp), $normalized)) {
                return intdiv($timestamp, $totp->getPeriod());
            }
        }

        return null;
    }

    /** @return string[] */
    private function issueRecoveryCodes(User $user): array
    {
        $this->recoveryCodeRepository->deleteAllForUser($user);
        $plaintexts = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; ++$i) {
            $raw = bin2hex(random_bytes(5));
            $plaintexts[] = substr($raw, 0, 5).'-'.substr($raw, 5);
            $code = new RecoveryCode();
            $code->setUser($user);
            $code->setCodeHash(hash('sha256', $raw));
            $this->persist($code);
        }

        return $plaintexts;
    }
}
