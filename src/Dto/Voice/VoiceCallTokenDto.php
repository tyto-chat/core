<?php

declare(strict_types=1);

namespace App\Dto\Voice;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class VoiceCallTokenDto
{
    public function __construct(
        #[Groups(['voice_call_token:read'])]
        public string $token = '',
        #[Groups(['voice_call_token:read'])]
        public string $url = '',
    ) {
    }
}
