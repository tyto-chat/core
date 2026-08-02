<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Utils\MentionExtractor;
use PHPUnit\Framework\TestCase;

class MentionExtractorTest extends TestCase
{
    public function testNoMentionsReturnsEmptyArray(): void
    {
        self::assertSame([], MentionExtractor::extractUserIds('Hello world'));
    }

    public function testSingleMentionReturnsId(): void
    {
        self::assertSame([42], MentionExtractor::extractUserIds('Hello [@Alice](user:42)!'));
    }

    public function testMultipleMentionsReturnAllIds(): void
    {
        $text = '[@Alice](user:42) and [@Bob](user:7) met today';
        self::assertSame([42, 7], MentionExtractor::extractUserIds($text));
    }

    public function testDuplicateMentionDeduplicates(): void
    {
        $text = '[@Alice](user:42) hey [@Alice](user:42) again';
        self::assertSame([42], MentionExtractor::extractUserIds($text));
    }

    public function testChannelMentionIsIgnored(): void
    {
        $text = 'Check [#general](channel:general) for updates';
        self::assertSame([], MentionExtractor::extractUserIds($text));
    }

    public function testMixedMentionsOnlyReturnsUserIds(): void
    {
        $text = '[@Alice](user:5) see [#dev](channel:dev) and [@Bob](user:9)';
        self::assertSame([5, 9], MentionExtractor::extractUserIds($text));
    }

    public function testMentionInsideCodeBlockIsNotExtracted(): void
    {
        // Mentions inside code blocks are preserved as-is by MarkdownSanitizer
        // and should not trigger notifications.
        $text = "Look at this:\n```\n[@Alice](user:42)\n```\nnot a real mention";
        self::assertSame([], MentionExtractor::extractUserIds($text));
    }

    public function testMentionInsideInlineCodeSpanIsNotExtracted(): void
    {
        $text = 'Example: `[@Alice](user:42)` — not a real mention';
        self::assertSame([], MentionExtractor::extractUserIds($text));
    }

    public function testNonNumericUserIdIsIgnored(): void
    {
        self::assertSame([], MentionExtractor::extractUserIds('[@Alice](user:abc)'));
    }

    public function testNegativeUserIdIsIgnored(): void
    {
        // Regex requires \d+ (positive digits only)
        self::assertSame([], MentionExtractor::extractUserIds('[@Alice](user:-1)'));
    }

    public function testWhitespaceInUserIdIsIgnored(): void
    {
        self::assertSame([], MentionExtractor::extractUserIds('[@Alice](user: 42)'));
    }

    public function testNoBroadcastsReturnsEmptyArray(): void
    {
        self::assertSame([], MentionExtractor::extractBroadcasts('Hello world'));
    }

    public function testExtractsChannelAndHereBroadcasts(): void
    {
        $text = 'Heads up [@channel](broadcast:channel) and [@here](broadcast:here)';
        self::assertSame(['channel', 'here'], MentionExtractor::extractBroadcasts($text));
    }

    public function testDuplicateBroadcastDeduplicates(): void
    {
        $text = '[@channel](broadcast:channel) again [@channel](broadcast:channel)';
        self::assertSame(['channel'], MentionExtractor::extractBroadcasts($text));
    }

    public function testUnknownBroadcastScopeIsIgnored(): void
    {
        self::assertSame([], MentionExtractor::extractBroadcasts('[@all](broadcast:everyone)'));
    }

    public function testBroadcastInsideCodeSpanIsIgnored(): void
    {
        $text = 'Example: `[@channel](broadcast:channel)` — not real';
        self::assertSame([], MentionExtractor::extractBroadcasts($text));
    }
}
