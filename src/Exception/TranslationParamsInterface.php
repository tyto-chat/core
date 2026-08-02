<?php

declare(strict_types=1);

namespace App\Exception;

interface TranslationParamsInterface
{
    /** @return array<string, string> */
    public function translationParams(): array;
}
