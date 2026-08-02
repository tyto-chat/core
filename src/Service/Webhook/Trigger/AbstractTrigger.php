<?php

declare(strict_types=1);

namespace App\Service\Webhook\Trigger;

use App\Dto\Webhook\WebhookEventContext;
use App\Service\Webhook\WebhookTriggerInterface;

/** An unset, null, or empty-string filter value means "match any". */
abstract class AbstractTrigger implements WebhookTriggerInterface
{
    /** @return string[] */
    public function getRequiredFilterFields(): array
    {
        return [];
    }

    public function matches(?array $filters, WebhookEventContext $ctx): bool
    {
        if (null === $filters) {
            return true;
        }

        foreach ($this->getFilterFields() as $field) {
            if (!array_key_exists($field, $filters)) {
                continue;
            }

            $filterValue = $filters[$field];

            if (null === $filterValue || '' === $filterValue) {
                continue; // unset filter = any
            }

            if ((string) $filterValue !== (string) $ctx->get($field)) {
                return false;
            }
        }

        return true;
    }
}
