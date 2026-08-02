<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminWebhookTriggerDto;
use App\Dto\Admin\AdminWebhookTriggersDto;
use App\Service\Webhook\WebhookTriggerRegistry;

/**
 * @implements ProviderInterface<AdminWebhookTriggersDto>
 */
final readonly class AdminWebhookTriggersProvider implements ProviderInterface
{
    public function __construct(
        private WebhookTriggerRegistry $triggerRegistry,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookTriggersDto
    {
        $dto = new AdminWebhookTriggersDto();
        foreach ($this->triggerRegistry->all() as $trigger) {
            $row = new AdminWebhookTriggerDto();
            $row->key = $trigger->getKey();
            $row->label = $trigger->getLabel();
            $row->description = $trigger->getDescription();
            $row->filterFields = $trigger->getFilterFields();
            $row->requiredFilterFields = $trigger->getRequiredFilterFields();
            $dto->triggers[] = $row;
        }

        return $dto;
    }
}
