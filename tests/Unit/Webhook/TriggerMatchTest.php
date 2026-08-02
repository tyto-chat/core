<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Dto\Webhook\WebhookEventContext;
use App\Service\Webhook\Trigger\MessageCreatedTrigger;
use App\Service\Webhook\Trigger\MessageRepliedTrigger;
use App\Service\Webhook\Trigger\ModerationActionTrigger;
use App\Service\Webhook\Trigger\ReactionAddedTrigger;
use PHPUnit\Framework\TestCase;

class TriggerMatchTest extends TestCase
{
    public function testReactionTriggerMatchesEmojiFilter(): void
    {
        $t = new ReactionAddedTrigger();
        $ctx = new WebhookEventContext(null, ['messageId' => 'abc-123', 'emoji' => '👍']);

        self::assertTrue($t->matches(null, $ctx));                       // no filter = any
        self::assertTrue($t->matches(['emoji' => '👍'], $ctx));          // match
        self::assertFalse($t->matches(['emoji' => '🎉'], $ctx));         // mismatch
        self::assertTrue($t->matches(['messageId' => 'abc-123'], $ctx)); // message match
        self::assertFalse($t->matches(['messageId' => 'other'], $ctx));  // message mismatch
        self::assertSame('reaction.added', $t->getKey());
    }

    public function testReactionTriggerEmptyFilterMatchesAny(): void
    {
        $t = new ReactionAddedTrigger();
        $ctx = new WebhookEventContext(null, ['messageId' => 'abc-123', 'emoji' => '❤️']);

        self::assertTrue($t->matches([], $ctx));
        self::assertTrue($t->matches(['emoji' => null], $ctx));
        self::assertTrue($t->matches(['emoji' => ''], $ctx));
    }

    public function testReactionTriggerFilterFields(): void
    {
        $t = new ReactionAddedTrigger();
        self::assertSame(['messageId', 'emoji'], $t->getFilterFields());
    }

    public function testMessageCreatedTriggerMatchesCommunityAndChannelFilters(): void
    {
        $t = new MessageCreatedTrigger();
        $ctx = new WebhookEventContext(null, ['communityId' => 1, 'channelId' => 2]);

        self::assertTrue($t->matches(null, $ctx));
        self::assertTrue($t->matches(['communityId' => 1], $ctx));
        self::assertFalse($t->matches(['communityId' => 99], $ctx));
        self::assertTrue($t->matches(['channelId' => 2], $ctx));
        self::assertFalse($t->matches(['channelId' => 99], $ctx));
        self::assertTrue($t->matches(['communityId' => 1, 'channelId' => 2], $ctx));
        self::assertFalse($t->matches(['communityId' => 1, 'channelId' => 99], $ctx));
        self::assertSame('message.created', $t->getKey());
    }

    public function testMessageCreatedTriggerFilterFields(): void
    {
        $t = new MessageCreatedTrigger();
        self::assertSame(['communityId', 'channelId'], $t->getFilterFields());
    }

    public function testMessageRepliedTriggerMatchesCommunityAndChannelFilters(): void
    {
        $t = new MessageRepliedTrigger();
        $ctx = new WebhookEventContext(null, ['communityId' => 3, 'channelId' => 7]);

        self::assertTrue($t->matches(null, $ctx));
        self::assertTrue($t->matches(['communityId' => 3], $ctx));
        self::assertFalse($t->matches(['communityId' => 999], $ctx));
        self::assertTrue($t->matches(['channelId' => 7], $ctx));
        self::assertFalse($t->matches(['channelId' => 999], $ctx));
        self::assertSame('message.replied', $t->getKey());
    }

    public function testMessageRepliedTriggerFilterFields(): void
    {
        $t = new MessageRepliedTrigger();
        self::assertSame(['communityId', 'channelId'], $t->getFilterFields());
    }

    public function testModerationActionTriggerMatchesCommunityAndActionType(): void
    {
        $t = new ModerationActionTrigger();
        $ctx = new WebhookEventContext(null, ['communityId' => 1, 'actionType' => 'ban']);

        self::assertTrue($t->matches(null, $ctx));
        self::assertTrue($t->matches(['communityId' => 1], $ctx));
        self::assertFalse($t->matches(['communityId' => 99], $ctx));
        self::assertTrue($t->matches(['actionType' => 'ban'], $ctx));
        self::assertFalse($t->matches(['actionType' => 'warn'], $ctx));
        self::assertSame('moderation.action', $t->getKey());
    }

    public function testModerationActionTriggerFilterFields(): void
    {
        $t = new ModerationActionTrigger();
        self::assertSame(['communityId', 'actionType'], $t->getFilterFields());
    }

    public function testMessageCreatedBuildPayload(): void
    {
        $t = new MessageCreatedTrigger();
        $ctx = new WebhookEventContext(null, [
            'communityId' => 1,
            'channelId' => 2,
            'messageId' => 'uuid-1',
            'messageText' => 'Hello',
            'authorId' => 10,
            'authorName' => 'Alice',
            'createdAt' => 1000000,
        ]);

        $payload = $t->buildPayload($ctx);

        self::assertSame('message.created', $payload['event']);
        self::assertSame('uuid-1', $payload['message']['id']);
        self::assertSame('Hello', $payload['message']['text']);
        self::assertSame(10, $payload['message']['authorId']);
        self::assertSame('Alice', $payload['message']['authorName']);
        self::assertSame(2, $payload['channelId']);
        self::assertSame(1, $payload['communityId']);
        self::assertSame(1000000, $payload['createdAt']);
    }

    public function testMessageRepliedBuildPayload(): void
    {
        $t = new MessageRepliedTrigger();
        $ctx = new WebhookEventContext(null, [
            'communityId' => 1,
            'channelId' => 2,
            'messageId' => 'uuid-reply',
            'messageText' => 'Reply text',
            'authorId' => 10,
            'authorName' => 'Alice',
            'createdAt' => 1000000,
            'rootMessageId' => 'uuid-root',
        ]);

        $payload = $t->buildPayload($ctx);

        self::assertSame('message.replied', $payload['event']);
        self::assertSame('uuid-root', $payload['rootMessageId']);
    }

    public function testReactionAddedBuildPayload(): void
    {
        $t = new ReactionAddedTrigger();
        $ctx = new WebhookEventContext(null, [
            'communityId' => 1,
            'channelId' => 2,
            'emoji' => '👍',
            'messageId' => 'uuid-msg',
            'reactorId' => 5,
        ]);

        $payload = $t->buildPayload($ctx);

        self::assertSame('reaction.added', $payload['event']);
        self::assertSame('👍', $payload['emoji']);
        self::assertSame('uuid-msg', $payload['messageId']);
        self::assertSame(5, $payload['reactorId']);
    }

    public function testModerationActionBuildPayload(): void
    {
        $t = new ModerationActionTrigger();
        $ctx = new WebhookEventContext(null, [
            'communityId' => 1,
            'actionType' => 'timeout',
            'targetUserId' => 20,
            'moderatorId' => 3,
            'reason' => 'Spamming',
        ]);

        $payload = $t->buildPayload($ctx);

        self::assertSame('moderation.action', $payload['event']);
        self::assertSame('timeout', $payload['actionType']);
        self::assertSame(20, $payload['targetUserId']);
        self::assertSame(3, $payload['moderatorId']);
        self::assertSame('Spamming', $payload['reason']);
    }
}
