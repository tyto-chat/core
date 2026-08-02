<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

interface ExportDownloadLimiterInterface
{
    public function incrementAndCheck(string $token, int $ttlSeconds): bool;
}
