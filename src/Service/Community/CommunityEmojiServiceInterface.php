<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityEmoji;
use App\Entity\MediaObject;

interface CommunityEmojiServiceInterface
{
    public const string SHORTCODE_PATTERN = '/^:[a-z0-9_-]{2,32}:$/';

    /** @return CommunityEmoji[] */
    public function getAllForCommunity(Community $community): array;

    public function findByShortcode(Community $community, string $shortcode): ?CommunityEmoji;

    /** Ungated reverse lookup — used by media authz to resolve an emoji image's community. */
    public function findByImage(MediaObject $image): ?CommunityEmoji;

    /** Format invariants (shortcode pattern, name length) — callable before the image is persisted. */
    public function assertValidCustomEmojiInput(string $shortcode, ?string $name): void;

    public function newCustom(Community $community, string $shortcode, MediaObject $image, ?string $name = null): CommunityEmoji;

    public function delete(CommunityEmoji $emoji): void;
}
