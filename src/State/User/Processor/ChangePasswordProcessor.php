<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\ChangePasswordDto;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<ChangePasswordDto, void>
 */
final readonly class ChangePasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->userService->changePassword($data);
    }
}
