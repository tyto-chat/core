<?php

declare(strict_types=1);

namespace App\Enum\IpReputation;

enum IpReputationVerdict: string
{
    case Clean = 'clean';
    case Flagged = 'flagged';
    case Unavailable = 'unavailable';
}
