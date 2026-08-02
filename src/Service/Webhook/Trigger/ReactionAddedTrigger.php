<?php

declare(strict_types=1);

namespace App\Service\Webhook\Trigger;

use App\Dto\Webhook\WebhookEventContext;

/** Emitter context keys: communityId, channelId, emoji, messageId, reactorId. */
final class ReactionAddedTrigger extends AbstractTrigger
{
    public function getKey(): string
    {
        return 'reaction.added';
    }

    public function getLabel(): string
    {
        return 'Reaction added';
    }

    public function getDescription(): string
    {
        return 'Fires when a user adds a reaction to a message.';
    }

    public function getFilterFields(): array
    {
        return ['messageId', 'emoji'];
    }

    public function getRequiredFilterFields(): array
    {
        // Both mandatory — otherwise this would fire on every reaction server-wide.
        return ['messageId', 'emoji'];
    }

    public function buildPayload(WebhookEventContext $ctx): array
    {
        return [
            'event' => 'reaction.added',
            'emoji' => $ctx->get('emoji'),
            'messageId' => $ctx->get('messageId'),
            'reactorId' => $ctx->get('reactorId'),
            'channelId' => $ctx->get('channelId'),
            'communityId' => $ctx->get('communityId'),
        ];
    }
}
