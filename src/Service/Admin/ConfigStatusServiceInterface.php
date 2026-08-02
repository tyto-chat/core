<?php

declare(strict_types=1);

namespace App\Service\Admin;

interface ConfigStatusServiceInterface
{
    public function isSmtpConfigured(): bool;

    public function isDefaultBotConfigured(): bool;

    public function isMercureConfigured(): bool;

    public function isMeiliConfigured(): bool;
}
