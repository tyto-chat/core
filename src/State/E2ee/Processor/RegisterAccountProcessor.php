<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\AccountDto;
use App\Dto\E2ee\RegisterAccountDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProcessorInterface<RegisterAccountDto, AccountDto>
 */
final readonly class RegisterAccountProcessor implements ProcessorInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AccountDto
    {
        \assert(null !== $data->publicKey);

        return AccountDto::fromAccount($this->e2ee->registerAccount($data->publicKey));
    }
}
