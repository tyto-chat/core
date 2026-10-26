<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\User\UserBlockServiceInterface;

/**
 * @implements ProcessorInterface<mixed, void>
 */
final readonly class UnblockUserProcessor implements ProcessorInterface
{
    public function __construct(private UserBlockServiceInterface $blocks)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->blocks->unblock((int) $uriVariables['userId']);
    }
}
