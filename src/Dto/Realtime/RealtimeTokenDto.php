<?php

declare(strict_types=1);

namespace App\Dto\Realtime;

use Symfony\Component\Serializer\Attribute\Groups;

class RealtimeTokenDto
{
    #[Groups(['realtime_token:read'])]
    public ?string $token = null;

    #[Groups(['realtime_token:read'])]
    public ?int $expiresAt = null;
}
