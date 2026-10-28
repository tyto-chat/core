<?php

declare(strict_types=1);

namespace App\Utils;

use App\Entity\Message;
use App\Enum\Message\MessageKind;

final class MessageDocumentBuilder
{
    public static function isIndexable(Message $message): bool
    {
        return !$message->isDeleted()
            && MessageKind::System !== $message->getKind()
            && !($message->getConversation()?->isEncrypted() ?? false);
    }

    public static function toPlaintext(?string $rawHtml): string
    {
        if (null === $rawHtml || '' === $rawHtml) {
            return '';
        }

        $withShortcodes = preg_replace_callback(
            '/<img\b[^>]*\bdata-shortcode="([^"]+)"[^>]*>/i',
            static fn (array $m): string => ' '.$m[1].' ',
            $rawHtml,
        ) ?? $rawHtml;

        $plain = strip_tags($withShortcodes);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim($plain);
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Message $message): array
    {
        $channel = $message->getChannel();
        $conversation = $message->getConversation();
        $author = $message->getCreatedBy();

        // Message::text is transient — null on freshly-loaded entities (backfill), so fall back to the current revision.
        $rawText = $message->getText();
        if (null === $rawText || '' === $rawText) {
            $rawText = $message->getCurrentRevision()?->getText();
        }

        return [
            'id' => $message->getId(),
            'text' => self::toPlaintext($rawText),
            'authorId' => $author?->getId(),
            'authorName' => $author?->getProfile()->getName(),
            'createdAt' => $message->getCreatedAt()?->getTimestamp() ?? 0,
            'channelId' => $channel?->getId(),
            'conversationId' => $conversation?->getId(),
            'pageNumber' => $message->getPageNumber(),
        ];
    }
}
