<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Enum\Channel\ChannelPinState;
use Symfony\Component\Serializer\Attribute\Groups;

/** Input + echo for the per-channel pin-state setter; null clears the pin. */
final readonly class SetChannelPinStateDto
{
    public function __construct(
        #[Groups(['channel_pin_state:read', 'channel_pin_state:write'])]
        public ?ChannelPinState $pinState = null,
    ) {
    }
}
