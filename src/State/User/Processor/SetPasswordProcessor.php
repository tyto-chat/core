<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\PasswordResetDto;
use App\Service\User\ResetPasswordRequestServiceInterface;

/**
 * @implements ProcessorInterface<PasswordResetDto, void>
 */
final readonly class SetPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private ResetPasswordRequestServiceInterface $resetPasswordRequestService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->resetPasswordRequestService->resetPassword($data);
    }
}
