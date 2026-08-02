<?php

declare(strict_types=1);

namespace App\Dto\Search;

use App\Entity\Message;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class SearchHitDto
{
    public function __construct(
        #[Groups(['search:read'])]
        public string $messageIri,
        #[Groups(['search:read'])]
        public string $messageId,
        #[Groups(['search:read'])]
        public string $snippet,
        #[Groups(['search:read'])]
        public string $text,
        #[Groups(['search:read'])]
        public ?int $authorId,
        #[Groups(['search:read'])]
        public ?string $authorName,
        /** Epoch seconds. */
        #[Groups(['search:read'])]
        public ?int $createdAt,
        #[Groups(['search:read'])]
        public ?int $pageNumber,
        #[Groups(['search:read'])]
        public ?string $communityIdentifier,
        #[Groups(['search:read'])]
        public ?string $channelIdentifier,
        #[Groups(['search:read'])]
        public ?string $conversationIdentifier,
        #[Groups(['search:read'])]
        public ?Message $message,
    ) {
    }
}
