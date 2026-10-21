<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\MessageMarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageMarkdownRendererTest extends TestCase
{
    private MessageMarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MessageMarkdownRenderer();
    }

    public function testEmptyInputRendersNothing(): void
    {
        self::assertSame('', $this->renderer->render(null));
        self::assertSame('', $this->renderer->render("  \n\n "));
    }

    public function testInlineMarkdownBecomesHtml(): void
    {
        self::assertSame(
            '<p><strong>bold</strong> and <em>soft</em> with <code>x = 1</code></p>',
            $this->renderer->render('**bold** and *soft* with `x = 1`'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function mentions(): iterable
    {
        yield 'user' => ['[@Ann](user:12)', '<span class="mention-user" data-user-id="12">@Ann</span>'];
        yield 'channel' => ['[#general](channel:7)', '<span class="mention-channel" data-channel-id="7">#general</span>'];
        yield 'broadcast' => ['[@here](broadcast:here)', '<span class="mention-broadcast" data-broadcast="here">@here</span>'];
    }

    #[DataProvider('mentions')]
    public function testMentionLinksBecomeSpans(string $markdown, string $expected): void
    {
        self::assertSame('<p>hi '.$expected.'</p>', $this->renderer->render('hi '.$markdown));
    }

    public function testOrdinaryLinksKeepOnlyTheirText(): void
    {
        self::assertSame(
            '<p>see docs and https://example.com/x</p>',
            $this->renderer->render('see [docs](https://example.com) and https://example.com/x'),
        );
    }

    public function testStrikethroughLosesItsMarkersLikeTheClient(): void
    {
        self::assertSame('<p>gone now</p>', $this->renderer->render('~~gone~~ now'));
    }

    public function testBlocksFollowTheClientAllowlist(): void
    {
        $html = $this->renderer->render("## Title\n\n#### Small\n\n- one\n- two\n\n> quoted\n\n---");

        self::assertStringContainsString('<h2>Title</h2>', $html);
        self::assertStringContainsString('Small', $html);
        self::assertStringNotContainsString('<h4>', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>one</li>', $html);
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringNotContainsString('<hr', $html);
    }

    public function testLegacyHtmlPassesThroughTheAllowlist(): void
    {
        self::assertSame(
            '<p>yay <code>:party:</code></p>',
            $this->renderer->render('<p>yay <code>:party:</code></p>'),
        );
    }

    public function testDangerousHtmlIsNeutralised(): void
    {
        $html = $this->renderer->render('<p onclick="x()">a<script>alert(1)</script><a href="javascript:void(0)">b</a></p>');

        self::assertSame('<p>ab</p>', $html);
    }

    public function testImagesKeepOnlyAllowedAttributes(): void
    {
        self::assertSame(
            '<p><img src="/media/a.png" alt="pic"></p>',
            $this->renderer->render('![pic](/media/a.png)'),
        );
    }

    public function testParagraphNoiseIsTrimmedLikeTheClient(): void
    {
        self::assertSame(
            '<p>one</p><p>two</p>',
            $this->renderer->render("\r\n\r\none\r\n\r\n\r\n\r\ntwo\r\n\r\n"),
        );
    }

    public function testHardBreaksAreCappedAtTwo(): void
    {
        $html = $this->renderer->render("a  \n  \n  \n  \nb");

        self::assertLessThanOrEqual(2, substr_count($html, '<br>'));
    }
}
