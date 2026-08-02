<?php

declare(strict_types=1);

namespace App\Enum\Settings;

use App\Enum\BackedEnumValuesTrait;

enum SmtpEncryption: string
{
    use BackedEnumValuesTrait;

    case None = 'none';
    case Tls = 'tls';   // STARTTLS (typically port 587)
    case Ssl = 'ssl';   // implicit TLS (typically port 465)
}
