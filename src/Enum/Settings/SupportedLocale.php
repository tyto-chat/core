<?php

declare(strict_types=1);

namespace App\Enum\Settings;

use App\Enum\BackedEnumValuesTrait;

enum SupportedLocale: string
{
    use BackedEnumValuesTrait;

    case English = 'en';
    case Polish = 'pl';
    case French = 'fr';
    case German = 'de';
    case Spanish = 'es';
    case Italian = 'it';
    case Portuguese = 'pt';
    case Ukrainian = 'uk';
    case Dutch = 'nl';
    case Turkish = 'tr';
}
