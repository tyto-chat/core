<?php

declare(strict_types=1);

namespace App\Service\Reaction;

use App\Entity\Community;
use App\Entity\Message;
use App\Entity\Reaction;

interface ReactionServiceInterface
{
    public function new(Message $message, string $emoji): Reaction;

    public function delete(Reaction $reaction): void;

    /** @param string[] $shortcodes */
    public function removeCommunityReactionsForEmojis(Community $community, array $shortcodes): void;
}
