<?php

declare(strict_types=1);

namespace App\State\UserPreference\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserPreference\UpdateUserPreferencesDto;
use App\Entity\UserPreference;
use App\Service\User\UserPreferenceServiceInterface;

/**
 * @implements ProcessorInterface<UpdateUserPreferencesDto, UserPreference>
 */
final readonly class SaveUserPreferenceProcessor implements ProcessorInterface
{
    public function __construct(
        private UserPreferenceServiceInterface $service,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserPreference
    {
        return $this->service->update($this->service->getForCurrentUser(), $data);
    }
}
