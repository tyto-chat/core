<?php

declare(strict_types=1);

namespace App\Service\Webhook\Trigger;

use App\Dto\Webhook\WebhookEventContext;

/** Emitter context keys: communityId, actionType, targetUserId, moderatorId, reason. */
final class ModerationActionTrigger extends AbstractTrigger
{
    public function getKey(): string
    {
        return 'moderation.action';
    }

    public function getLabel(): string
    {
        return 'Moderation action';
    }

    public function getDescription(): string
    {
        return 'Fires when a moderation action is taken (warn, timeout, ban, server_ban).';
    }

    public function getFilterFields(): array
    {
        return ['communityId', 'actionType'];
    }

    public function buildPayload(WebhookEventContext $ctx): array
    {
        return [
            'event' => 'moderation.action',
            'actionType' => $ctx->get('actionType'),
            'targetUserId' => $ctx->get('targetUserId'),
            'moderatorId' => $ctx->get('moderatorId'),
            'reason' => $ctx->get('reason'),
            'communityId' => $ctx->get('communityId'),
        ];
    }
}
