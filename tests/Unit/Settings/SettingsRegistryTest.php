<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Enum\Settings\SettingType;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsRegistryTest extends TestCase
{
    public function testAllReturnsEveryDefWithUniqueKeys(): void
    {
        $all = Settings::all();
        $keys = array_map(static fn ($d) => $d->key, $all);
        self::assertSame($keys, array_unique($keys), 'duplicate setting keys');
        self::assertContains('serverName', $keys);
        self::assertContains('smtpPassword', $keys);
        self::assertContains('adminOnboardedAt', $keys);
        self::assertContains('avatarMaxSizeMb', $keys);
        self::assertContains('autoTimeoutHits', $keys);
        self::assertContains('defaultBotId', $keys);
        self::assertContains('welcomeBotId', $keys);
        self::assertContains('autoModeratorBotId', $keys);
        self::assertContains('communityEmojiMaxWidth', $keys);
        self::assertContains('communityEmojiMaxHeight', $keys);
        self::assertContains('ratePasswordResetLimit', $keys);
        self::assertContains('rateReportLimit', $keys);
        self::assertContains('rateTwoFactorLimit', $keys);
        self::assertContains('rateTwoFactorIntervalSeconds', $keys);
        self::assertContains('rateSessionRefreshLimit', $keys);
        self::assertContains('rateSessionRefreshIntervalSeconds', $keys);
        self::assertContains('termsContent', $keys);
        self::assertContains('requireRegistrationConsent', $keys);
        self::assertContains('listInServerCatalogue', $keys);
        self::assertContains('minimumAgeYears', $keys);
        self::assertContains('messageRetentionDays', $keys);
        self::assertCount(89, $all);
    }

    public function testByKeyResolves(): void
    {
        self::assertSame('rateLoginLimit', Settings::byKey('rateLoginLimit')?->key);
        self::assertNull(Settings::byKey('nope'));
    }

    public function testTypedDefaults(): void
    {
        self::assertSame(5, Settings::rateLoginLimit()->default);
        self::assertSame(SettingType::Int, Settings::rateLoginLimit()->type);
        self::assertTrue(Settings::smtpPassword()->secret);
        self::assertNull(Settings::smtpHost()->default);
    }

    public function testMigratedDefaults(): void
    {
        self::assertSame(25, Settings::maxAttachmentSizeMb()->default);
        self::assertSame(1000, Settings::avatarMaxWidth()->default);
        self::assertTrue(Settings::autoTimeoutEnabled()->default);
        self::assertSame(86400, Settings::autoTimeoutMaxSeconds()->default);
        self::assertSame(36000, Settings::autoTimeoutResetSeconds()->default);
        self::assertSame(8, Settings::digestHour()->default);
        self::assertSame(0, Settings::defaultBotId()->default);
        self::assertSame(0, Settings::welcomeBotId()->default);
        self::assertSame(0, Settings::autoModeratorBotId()->default);
        self::assertStringContainsString('image/png', (string) Settings::attachmentAllowedMimes()->default);
    }
}
