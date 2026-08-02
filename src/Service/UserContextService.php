<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final readonly class UserContextService implements UserContextServiceInterface
{
    private const string FIREWALL_NAME = 'main';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[\Override]
    public function runAs(User $user, callable $fn): mixed
    {
        $previous = $this->tokenStorage->getToken();
        $this->tokenStorage->setToken(
            new UsernamePasswordToken($user, self::FIREWALL_NAME, $user->getRoles())
        );

        try {
            return $fn();
        } finally {
            $this->tokenStorage->setToken($previous);
        }
    }
}
