<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\LeaveRequestInputDto;
use App\Service\E2ee\GroupDeliveryServiceInterface;

/**
 * @implements ProcessorInterface<LeaveRequestInputDto, void>
 */
final readonly class LeaveRequestProcessor implements ProcessorInterface
{
    public function __construct(private GroupDeliveryServiceInterface $delivery)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->delivery->recordLeaveRequest((string) $uriVariables['deviceId'], $data);
    }
}
