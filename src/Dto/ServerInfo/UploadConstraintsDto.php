<?php

declare(strict_types=1);

namespace App\Dto\ServerInfo;

use Symfony\Component\Serializer\Attribute\Groups;

class UploadConstraintsDto
{
    public function __construct(
        #[Groups(['server_info:read'])]
        public readonly string $avatarMaxSize,
        #[Groups(['server_info:read'])]
        public readonly int $avatarMaxWidth,
        #[Groups(['server_info:read'])]
        public readonly int $avatarMaxHeight,
        #[Groups(['server_info:read'])]
        public readonly string $logoMaxSize,
        #[Groups(['server_info:read'])]
        public readonly int $logoMaxWidth,
        #[Groups(['server_info:read'])]
        public readonly int $logoMaxHeight,
        #[Groups(['server_info:read'])]
        public readonly string $attachmentMaxSize,
        #[Groups(['server_info:read'])]
        public readonly string $attachmentAllowedMimes,
        #[Groups(['server_info:read'])]
        public readonly int $attachmentMaxPerMessage,
        #[Groups(['server_info:read'])]
        public readonly string $communityEmojiMaxSize,
        #[Groups(['server_info:read'])]
        public readonly int $communityEmojiMaxWidth,
        #[Groups(['server_info:read'])]
        public readonly int $communityEmojiMaxHeight,
        #[Groups(['server_info:read'])]
        public readonly string $communityEmojiAllowedMimes,
    ) {
    }
}
