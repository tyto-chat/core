<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @implements ProviderInterface<UserInterface>
 */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(private Security $security)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new AccessDeniedException();
        }

        return $user;
    }
}
