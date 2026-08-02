<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Enum\Legal\LegalDocumentType;

interface LegalDocumentServiceInterface
{
    public function getContent(LegalDocumentType $type): string;

    public function getDefault(LegalDocumentType $type): string;

    public function getRawOverride(LegalDocumentType $type): string;

    public function isCustomized(LegalDocumentType $type): bool;
}
