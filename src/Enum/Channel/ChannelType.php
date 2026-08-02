<?php

declare(strict_types=1);

namespace App\Enum\Channel;

enum ChannelType: string
{
    case Audio = 'audio';
    case Text = 'text';
}
