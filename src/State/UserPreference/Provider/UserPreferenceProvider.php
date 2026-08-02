<?php

declare(strict_types=1);

namespace App\State\UserPreference\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\UserPreference;
use App\Service\User\UserPreferenceServiceInterface;

/**
 * @implements ProviderInterface<UserPreference>
 */
final readonly class UserPreferenceProvider implements ProviderInterface
{
    public function __construct(
        private UserPreferenceServiceInterface $service,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserPreference
    {
        return $this->service->getForCurrentUser();
    }
}
