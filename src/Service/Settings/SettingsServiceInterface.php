<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Entity\User;
use App\Settings\SettingDef;

interface SettingsServiceInterface
{
    /**
     * @template T
     *
     * @param SettingDef<T> $def
     *
     * @return T
     */
    public function get(SettingDef $def): mixed;

    /**
     * @param SettingDef<mixed> $def
     */
    public function has(SettingDef $def): bool;

    public function isSmtpConfigured(): bool;

    public function applyPatch(ServerConfigPatchDto $dto): void;

    public function completeAdminOnboarding(): void;

    public function isAdminOnboarded(): bool;

    /**
     * @return array{at: ?\DateTimeImmutable, by: ?User}
     */
    public function lastUpdate(): array;
}
