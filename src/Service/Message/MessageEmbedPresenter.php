<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Dto\Message\MessageEmbedDto;
use App\Entity\Community;
use App\Enum\Message\MessageKind;
use App\Exception\Message\MessageNotFoundException;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Utils\MessageDocumentBuilder;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class MessageEmbedPresenter implements MessageEmbedPresenterInterface
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const string SHORTCODE_PATTERN = '/(:[a-z0-9_-]{2,32}:)/';
    private const int MAX_MEDIA = 2;
    private const int USER_COLORS = 12;

    public function __construct(
        private MessageServiceInterface $messageService,
        private CommunityEmojiServiceInterface $emojiService,
        private NormalizerInterface $normalizer,
        private HtmlSanitizerInterface $messageEmbedSanitizer,
    ) {
    }

    #[\Override]
    public function present(string $uuid): ?MessageEmbedDto
    {
        if (1 !== preg_match(self::UUID_PATTERN, $uuid)) {
            return null;
        }
        try {
            $message = $this->messageService->getById($uuid);
        } catch (MessageNotFoundException|AccessDeniedException) {
            return null;
        }
        $channel = $message->getChannel();
        $community = $channel?->getCommunity();
        if (null === $channel || null === $community || $message->isDeleted() || MessageKind::System === $message->getKind()) {
            return null;
        }
        $this->messageService->hydrateText($message);

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($message, 'jsonld', ['groups' => ['message:read']]);
        $emojiUrls = $this->emojiUrls($community);
        $text = is_string($data['text'] ?? null) ? $data['text'] : '';

        $media = [];
        $fileCount = 0;
        foreach (is_array($data['attachments'] ?? null) ? $data['attachments'] : [] as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $mime = is_string($attachment['mimeType'] ?? null) ? $attachment['mimeType'] : '';
            $url = is_string($attachment['contentUrl'] ?? null) ? $attachment['contentUrl'] : null;
            $isImage = str_starts_with($mime, 'image/');
            $isVideo = str_starts_with($mime, 'video/');
            if (null === $url || (!$isImage && !$isVideo)) {
                ++$fileCount;
                continue;
            }
            $media[] = [
                'url' => $url,
                'video' => $isVideo,
                'name' => is_string($attachment['originalName'] ?? null) ? $attachment['originalName'] : '',
            ];
        }

        $reactions = [];
        foreach (is_array($data['reactions'] ?? null) ? $data['reactions'] : [] as $key => $users) {
            $key = (string) $key;
            $reactions[] = [
                'key' => $key,
                'imageUrl' => $emojiUrls[$key] ?? null,
                'count' => is_countable($users) ? count($users) : 0,
            ];
        }

        $profile = is_array($data['createdBy']['profile'] ?? null) ? $data['createdBy']['profile'] : [];
        $avatar = is_array($profile['avatar']['contentUrl'] ?? null) ? $profile['avatar']['contentUrl'] : [];

        return new MessageEmbedDto(
            uuid: strtolower($uuid),
            authorName: is_string($profile['name'] ?? null) && '' !== $profile['name'] ? $profile['name'] : '?',
            authorColor: self::colorIndex(is_string($profile['@id'] ?? null) ? $profile['@id'] : null),
            avatarUrl: is_string($avatar['sm'] ?? null) ? $avatar['sm'] : null,
            createdAt: $message->getCreatedAt() ?? new \DateTimeImmutable(),
            bodyHtml: $this->decorateEmojis($this->messageEmbedSanitizer->sanitize($text), $emojiUrls),
            plaintext: MessageDocumentBuilder::toPlaintext($text),
            media: array_slice($media, 0, self::MAX_MEDIA),
            extraMediaCount: max(0, count($media) - self::MAX_MEDIA),
            fileCount: $fileCount,
            reactions: $reactions,
            replyCount: $message->getReplyCount(),
            channelIdentifier: $channel->getIdentifier() ?? '',
            communityName: $community->getName() ?? $community->getIdentifier() ?? '',
        );
    }

    /** @return array<string, string> shortcode → image URL */
    private function emojiUrls(Community $community): array
    {
        $urls = [];
        foreach ($this->emojiService->getAllForCommunity($community) as $emoji) {
            $image = $emoji->getImage();
            if (null === $image) {
                continue;
            }
            /** @var array<string, mixed> $normalized */
            $normalized = $this->normalizer->normalize($image, 'jsonld', ['groups' => ['community_emoji:read']]);
            if (is_string($normalized['contentUrl'] ?? null)) {
                $urls[$emoji->getShortcode()] = $normalized['contentUrl'];
            }
        }

        return $urls;
    }

    /** @param array<string, string> $emojiUrls */
    private function decorateEmojis(string $html, array $emojiUrls): string
    {
        if ([] === $emojiUrls || !str_contains($html, ':')) {
            return $html;
        }
        $document = \Dom\HTMLDocument::createFromString('<body>'.$html.'</body>', LIBXML_NOERROR);
        foreach (self::decoratableTextNodes($document->body) as $node) {
            $parts = preg_split(self::SHORTCODE_PATTERN, $node->data, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            if (false === $parts || count($parts) < 2) {
                continue;
            }
            $replacement = [];
            foreach ($parts as $part) {
                if (isset($emojiUrls[$part])) {
                    $img = $document->createElement('img');
                    $img->setAttribute('src', $emojiUrls[$part]);
                    $img->setAttribute('alt', $part);
                    $img->setAttribute('title', $part);
                    $img->setAttribute('class', 'embed-emoji');
                    $img->setAttribute('loading', 'lazy');
                    $replacement[] = $img;
                } else {
                    $replacement[] = $document->createTextNode($part);
                }
            }
            $node->replaceWith(...$replacement);
        }

        return $document->body->innerHTML;
    }

    /** @return list<\Dom\Text> */
    private static function decoratableTextNodes(?\Dom\Node $root): array
    {
        if (null === $root) {
            return [];
        }
        $nodes = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \Dom\Text) {
                $nodes[] = $child;
            } elseif ($child instanceof \Dom\Element && !in_array(strtolower($child->tagName), ['code', 'pre'], true)) {
                $nodes = [...$nodes, ...self::decoratableTextNodes($child)];
            }
        }

        return $nodes;
    }

    private static function colorIndex(?string $profileIri): int
    {
        if (null === $profileIri || '' === $profileIri) {
            return 0;
        }
        $hash = 0;
        for ($i = 0, $length = strlen($profileIri); $i < $length; ++$i) {
            $hash = (($hash << 5) - $hash + ord($profileIri[$i])) & 0xFFFFFFFF;
            if ($hash >= 0x80000000) {
                $hash -= 0x100000000;
            }
        }

        return abs($hash) % self::USER_COLORS;
    }
}
