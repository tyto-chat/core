<?php

declare(strict_types=1);

namespace App\Enum\Settings;

use App\Enum\BackedEnumValuesTrait;

enum EncryptedDmsMode: string
{
    use BackedEnumValuesTrait;

    case Disabled = 'disabled';
    case Enabled = 'enabled';
    case Required = 'required';
}
