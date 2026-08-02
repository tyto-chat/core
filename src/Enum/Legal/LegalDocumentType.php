<?php

declare(strict_types=1);

namespace App\Enum\Legal;

enum LegalDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
}
