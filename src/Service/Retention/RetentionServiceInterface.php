<?php

declare(strict_types=1);

namespace App\Service\Retention;

interface RetentionServiceInterface
{
    /**
     * @return array{messages: int, attachments: int, notifications: int}
     */
    public function purge(): array;
}
