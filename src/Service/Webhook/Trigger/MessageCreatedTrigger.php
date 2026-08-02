<?php

declare(strict_types=1);

namespace App\Service\Webhook\Trigger;

use App\Dto\Webhook\WebhookEventContext;

/** Emitter context keys: communityId, channelId, messageId, messageText, authorId, authorName, createdAt. */
final class MessageCreatedTrigger extends AbstractTrigger
{
    public function getKey(): string
    {
        return 'message.created';
    }

    public function getLabel(): string
    {
        return 'Message created';
    }

    public function getDescription(): string
    {
        return 'Fires when a new standard message is posted to a channel.';
    }

    public function getFilterFields(): array
    {
        return ['communityId', 'channelId'];
    }

    public function buildPayload(WebhookEventContext $ctx): array
    {
        return [
            'event' => 'message.created',
            'message' => [
                'id' => $ctx->get('messageId'),
                'text' => $ctx->get('messageText'),
                'authorId' => $ctx->get('authorId'),
                'authorName' => $ctx->get('authorName'),
            ],
            'channelId' => $ctx->get('channelId'),
            'communityId' => $ctx->get('communityId'),
            'createdAt' => $ctx->get('createdAt'),
        ];
    }
}
