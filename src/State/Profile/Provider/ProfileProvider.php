<?php

declare(strict_types=1);

namespace App\State\Profile\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Profile;
use App\Service\User\ProfileServiceInterface;

/**
 * @implements ProviderInterface<Profile>
 */
final readonly class ProfileProvider implements ProviderInterface
{
    public function __construct(private ProfileServiceInterface $profileService)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Profile
    {
        return $this->profileService->get((int) $uriVariables['id']);
    }
}
