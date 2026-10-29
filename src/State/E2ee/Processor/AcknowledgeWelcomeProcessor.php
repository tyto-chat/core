<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\E2ee\GroupDeliveryServiceInterface;

/**
 * @implements ProcessorInterface<null, void>
 */
final readonly class AcknowledgeWelcomeProcessor implements ProcessorInterface
{
    public function __construct(private GroupDeliveryServiceInterface $delivery)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->delivery->acknowledgeWelcome((string) $uriVariables['welcomeId']);
    }
}
