<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<mixed, User>
 */
final readonly class CompleteOnboardingProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        return $this->userService->markOnboardedForCurrentUser();
    }
}
