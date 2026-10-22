<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Utils\Topics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TopicsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function topics(): iterable
    {
        yield 'user notifications' => [Topics::userNotifications(42), 'u/42/n'];
        yield 'user events' => [Topics::userEvents(42), 'u/42/e'];
        yield 'user conversation activity' => [Topics::userConversationActivity(42), 'u/42/d'];
        yield 'user presence' => [Topics::userPresence(42), 'u/42/p'];
        yield 'presence template' => [Topics::presenceTemplate(), 'u/{userId}/p'];
        yield 'community' => [Topics::community(7), 'k/7'];
        yield 'community activity' => [Topics::communityActivity(7), 'k/7/a'];
        yield 'community emojis' => [Topics::communityEmojis(7), 'k/7/e'];
        yield 'channel' => [Topics::channel(12), 'c/12'];
        yield 'channel threads' => [Topics::channelThreads(12), 'c/12/t'];
        yield 'channel participants' => [Topics::channelParticipants(12), 'c/12/p'];
        yield 'conversation' => [Topics::conversation(5), 'm/5'];
        yield 'conversation threads' => [Topics::conversationThreads(5), 'm/5/t'];
    }

    #[DataProvider('topics')]
    public function testTopic(string $actual, string $expected): void
    {
        self::assertSame($expected, $actual);
    }
}
