<?php

declare(strict_types=1);

namespace App\State\Profile\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Profile\UpdateProfileDto;
use App\Entity\Profile;
use App\Service\User\ProfileServiceInterface;

/**
 * @implements ProcessorInterface<UpdateProfileDto, Profile>
 */
final readonly class UpdateProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private ProfileServiceInterface $profileService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Profile
    {
        /** @var UpdateProfileDto $data */
        /** @var Profile $profile */
        $profile = $context['read_data'];

        return $this->profileService->update($profile, $data);
    }
}
