<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use App\Enum\User\UserRole;
use App\Service\Security\SecretBoxInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string TEST_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SecretBoxInterface $secretBox,
    ) {
        parent::__construct();
    }

    #[\Override]
    public static function class(): string
    {
        return User::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'password' => 'password',
            'roles' => [UserRole::User->value],
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this->afterInstantiate(function (User $user): void {
            $user->setPassword($this->hasher->hashPassword($user, $user->getPassword()));
            if ('' === $user->getProfile()->getName()) {
                $user->getProfile()->setName(self::faker()->name());
            }
        });
    }

    public function admin(): static
    {
        return $this->with(['roles' => [UserRole::Admin->value]]);
    }

    public function bot(): static
    {
        return $this->with(['isBot' => true, 'roles' => [UserRole::Admin->value]]);
    }

    public function withPassword(string $password): static
    {
        return $this->with(['password' => $password]);
    }

    public function withTwoFactor(): static
    {
        return $this->with(['totpEnabledAt' => new \DateTimeImmutable()])
            ->afterInstantiate(function (User $user): void {
                $user->setTwoFactorSecret($this->secretBox->encrypt(self::TEST_TOTP_SECRET));
            });
    }
}
