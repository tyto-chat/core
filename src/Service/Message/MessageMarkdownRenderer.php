<?php

declare(strict_types=1);

namespace App\Service\Message;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Mirrors the web client's message pipeline (marked → mention spans → DOMPurify allowlist → paragraph trimming)
 * so server-rendered cards look like the app. Keep the allowlists in step with client/src/utils/sanitize.ts.
 */
final class MessageMarkdownRenderer
{
    private const array ALLOWED_ELEMENTS = ['p', 'strong', 'em', 'u', 's', 'code', 'pre', 'span', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'br', 'blockquote', 'img'];
    private const array ALLOWED_ATTRIBUTES = ['class', 'data-channel-id', 'data-user-id', 'data-broadcast', 'data-internal-link', 'src', 'alt', 'title', 'loading', 'decoding'];
    private const array DROPPED_WITH_CONTENT = ['script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'title', 'textarea', 'select', 'head'];
    private const array MENTION_SCHEMES = [
        'user:' => ['mention-user', 'data-user-id'],
        'channel:' => ['mention-channel', 'data-channel-id'],
        'broadcast:' => ['mention-broadcast', 'data-broadcast'],
    ];
    private const int MAX_CONSECUTIVE_BREAKS = 2;

    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new StrikethroughExtension());
        $this->converter = new MarkdownConverter($environment);
    }

    public function render(?string $markdown): string
    {
        $normalized = trim(preg_replace('/\n{3,}/', "\n\n", str_replace("\r\n", "\n", $markdown ?? '')) ?? '');
        if ('' === $normalized) {
            return '';
        }

        $document = \Dom\HTMLDocument::createFromString(
            '<body>'.$this->converter->convert($normalized)->getContent().'</body>',
            LIBXML_NOERROR,
        );
        $body = $document->body;
        if (null === $body) {
            return '';
        }

        $this->convertMentions($document, $body);
        $this->applyAllowlist($body);
        $this->trimEmptyParagraphs($document, $body);

        return trim($body->innerHTML);
    }

    private function convertMentions(\Dom\HTMLDocument $document, \Dom\Element $body): void
    {
        foreach (self::MENTION_SCHEMES as $scheme => [$class, $attribute]) {
            foreach (iterator_to_array($body->querySelectorAll(sprintf('a[href^="%s"]', $scheme))) as $anchor) {
                $span = $document->createElement('span');
                $span->setAttribute('class', $class);
                $span->setAttribute($attribute, substr((string) $anchor->getAttribute('href'), strlen($scheme)));
                $span->textContent = $anchor->textContent;
                $anchor->replaceWith($span);
            }
        }
    }

    private function applyAllowlist(\Dom\Element $root): void
    {
        foreach (iterator_to_array($root->childNodes) as $child) {
            if (!$child instanceof \Dom\Element) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
                $child->remove();
                continue;
            }
            $this->applyAllowlist($child);
            if (!in_array($tag, self::ALLOWED_ELEMENTS, true)) {
                $child->replaceWith(...iterator_to_array($child->childNodes));
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), self::ALLOWED_ATTRIBUTES, true)) {
                    $child->removeAttribute($attribute->name);
                }
            }
        }
    }

    private function trimEmptyParagraphs(\Dom\HTMLDocument $document, \Dom\Element $body): void
    {
        foreach (iterator_to_array($body->childNodes) as $node) {
            if ($node instanceof \Dom\Text && '' === trim($node->data)) {
                $body->removeChild($node);
            }
        }

        while (null !== ($first = $body->firstElementChild) && self::isEmptyParagraph($first)) {
            $first->remove();
        }
        while (null !== ($last = $body->lastElementChild) && self::isEmptyParagraph($last)) {
            $last->remove();
        }

        $previousEmpty = false;
        foreach (iterator_to_array($body->children) as $child) {
            $empty = self::isEmptyParagraph($child);
            if ($empty && $previousEmpty) {
                $child->remove();
                continue;
            }
            if ($empty) {
                $child->replaceChildren($document->createElement('br'));
            }
            $previousEmpty = $empty;
        }

        foreach (iterator_to_array($body->querySelectorAll('p')) as $paragraph) {
            while (null !== ($first = $paragraph->firstChild) && 'BR' === $first->nodeName) {
                $paragraph->removeChild($first);
            }
            while (null !== ($last = $paragraph->lastChild) && 'BR' === $last->nodeName) {
                $paragraph->removeChild($last);
            }
            $run = 0;
            foreach (iterator_to_array($paragraph->childNodes) as $node) {
                $run = 'BR' === $node->nodeName ? $run + 1 : 0;
                if ($run > self::MAX_CONSECUTIVE_BREAKS) {
                    $paragraph->removeChild($node);
                }
            }
        }
    }

    private static function isEmptyParagraph(\Dom\Element $element): bool
    {
        return 'P' === $element->tagName
            && '' === trim($element->textContent ?? '')
            && null === $element->querySelector(':not(br)');
    }
}
