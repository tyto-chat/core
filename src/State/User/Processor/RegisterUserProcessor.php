<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\CreateUserDto;
use App\Entity\User;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<CreateUserDto, User>
 */
final readonly class RegisterUserProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly UserServiceInterface $userService,
    ) {
    }

    #[\Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        \assert($data instanceof CreateUserDto);

        return $this->userService->register($data);
    }
}
