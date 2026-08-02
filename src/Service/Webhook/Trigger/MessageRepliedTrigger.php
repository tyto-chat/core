<?php

declare(strict_types=1);

namespace App\Service\Webhook\Trigger;

use App\Dto\Webhook\WebhookEventContext;

/** Emitter context keys: communityId, channelId, messageId, messageText, authorId, authorName, createdAt, rootMessageId. */
final class MessageRepliedTrigger extends AbstractTrigger
{
    public function getKey(): string
    {
        return 'message.replied';
    }

    public function getLabel(): string
    {
        return 'Message replied';
    }

    public function getDescription(): string
    {
        return 'Fires when a reply is posted in a channel thread.';
    }

    public function getFilterFields(): array
    {
        return ['communityId', 'channelId'];
    }

    public function buildPayload(WebhookEventContext $ctx): array
    {
        return [
            'event' => 'message.replied',
            'message' => [
                'id' => $ctx->get('messageId'),
                'text' => $ctx->get('messageText'),
                'authorId' => $ctx->get('authorId'),
                'authorName' => $ctx->get('authorName'),
            ],
            'rootMessageId' => $ctx->get('rootMessageId'),
            'channelId' => $ctx->get('channelId'),
            'communityId' => $ctx->get('communityId'),
            'createdAt' => $ctx->get('createdAt'),
        ];
    }
}
