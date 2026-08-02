<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enum\Settings\SettingType;
use App\Enum\Settings\SmtpEncryption;
use App\Enum\Settings\SupportedLocale;

/** Adding a setting = one accessor method + its entry in all() — a def missing from all() is invisible to patch/audit. */
final class Settings
{
    /** @return SettingDef<string> */
    public static function serverName(): SettingDef
    {
        return new SettingDef('serverName', SettingType::String, 'Tyto', normalizer: self::trimmed());
    }

    /** @return SettingDef<string> */
    public static function serverDescription(): SettingDef
    {
        return new SettingDef('serverDescription', SettingType::String, '', normalizer: self::trimmed());
    }

    /** @return SettingDef<?string> */
    public static function accentColor(): SettingDef
    {
        return new SettingDef('accentColor', SettingType::String, null, normalizer: static fn (mixed $v): mixed => is_string($v) ? strtolower($v) : $v);
    }

    /** @return SettingDef<bool> */
    public static function registrationEnabled(): SettingDef
    {
        return new SettingDef('registrationEnabled', SettingType::Bool, true);
    }

    /** @return SettingDef<string> Terms of Service markdown; empty falls back to the shipped default. */
    public static function termsContent(): SettingDef
    {
        return new SettingDef('termsContent', SettingType::String, '');
    }

    /** @return SettingDef<string> Privacy Policy markdown; empty falls back to the shipped default. */
    public static function privacyContent(): SettingDef
    {
        return new SettingDef('privacyContent', SettingType::String, '');
    }

    /** @return SettingDef<?string> */
    public static function legalContactEmail(): SettingDef
    {
        return new SettingDef('legalContactEmail', SettingType::String, null);
    }

    /** @return SettingDef<bool> Require users to accept the terms/privacy policy at registration. */
    public static function requireRegistrationConsent(): SettingDef
    {
        return new SettingDef('requireRegistrationConsent', SettingType::Bool, false);
    }

    /** @return SettingDef<bool> */
    public static function listInServerCatalogue(): SettingDef
    {
        return new SettingDef('listInServerCatalogue', SettingType::Bool, false);
    }

    /** @return SettingDef<int> Minimum age in years to register; 0 disables the age gate. */
    public static function minimumAgeYears(): SettingDef
    {
        return new SettingDef('minimumAgeYears', SettingType::Int, 0);
    }

    /** @return SettingDef<int> Redact message content older than N days; 0 keeps messages forever. */
    public static function messageRetentionDays(): SettingDef
    {
        return new SettingDef('messageRetentionDays', SettingType::Int, 0);
    }

    /** @return SettingDef<int> Delete notifications older than N days; 0 keeps them forever. */
    public static function notificationRetentionDays(): SettingDef
    {
        return new SettingDef('notificationRetentionDays', SettingType::Int, 0);
    }

    /** @return SettingDef<int> Delete channels N days after archiving; 0 keeps them forever. */
    public static function archivedChannelRetentionDays(): SettingDef
    {
        return new SettingDef('archivedChannelRetentionDays', SettingType::Int, 0);
    }

    /** @return SettingDef<SupportedLocale> */
    public static function defaultLocale(): SettingDef
    {
        return new SettingDef('defaultLocale', SettingType::Enum, SupportedLocale::English, false, SupportedLocale::class);
    }

    /** @return SettingDef<?int> */
    public static function defaultAttachmentRetentionDays(): SettingDef
    {
        return new SettingDef('defaultAttachmentRetentionDays', SettingType::Int, null);
    }

    /** @return SettingDef<int> Free-disk %% below which the pressure purge opens; 0 keeps it off. */
    public static function diskPurgeTriggerPercent(): SettingDef
    {
        return new SettingDef('diskPurgeTriggerPercent', SettingType::Int, 0);
    }

    /** @return SettingDef<int> Free-disk %% the pressure purge tries to restore. */
    public static function diskPurgeTargetPercent(): SettingDef
    {
        return new SettingDef('diskPurgeTargetPercent', SettingType::Int, 15);
    }

    /** @return SettingDef<int> Attachments younger than this many days are never pressure-purged. */
    public static function diskPurgeMinAgeDays(): SettingDef
    {
        return new SettingDef('diskPurgeMinAgeDays', SettingType::Int, 14);
    }

    /** @return SettingDef<bool> */
    public static function diskPurgeIncludeDms(): SettingDef
    {
        return new SettingDef('diskPurgeIncludeDms', SettingType::Bool, false);
    }

    /** @return SettingDef<int> */
    public static function maxAttachmentSizeMb(): SettingDef
    {
        return new SettingDef('maxAttachmentSizeMb', SettingType::Int, 25);
    }

    /** @return SettingDef<int> */
    public static function maxAttachmentsPerMessage(): SettingDef
    {
        return new SettingDef('maxAttachmentsPerMessage', SettingType::Int, 10);
    }

    /** @return SettingDef<string> */
    public static function defaultWelcomeChannelName(): SettingDef
    {
        return new SettingDef('defaultWelcomeChannelName', SettingType::String, 'general', normalizer: self::trimmed());
    }

    /** @return SettingDef<int> */
    public static function rateLoginLimit(): SettingDef
    {
        return new SettingDef('rateLoginLimit', SettingType::Int, 5);
    }

    /** @return SettingDef<int> */
    public static function rateLoginIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateLoginIntervalSeconds', SettingType::Int, 60);
    }

    /**
     * Session refresh is a normal, frequent operation for a signed-in user — every
     * reload near token expiry costs one. It must NOT share the login budget, and
     * the limit has to clear a whole office behind one NAT address.
     *
     * @return SettingDef<int>
     */
    public static function rateSessionRefreshLimit(): SettingDef
    {
        return new SettingDef('rateSessionRefreshLimit', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function rateSessionRefreshIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateSessionRefreshIntervalSeconds', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function rateRegisterLimit(): SettingDef
    {
        return new SettingDef('rateRegisterLimit', SettingType::Int, 3);
    }

    /** @return SettingDef<int> */
    public static function rateRegisterIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateRegisterIntervalSeconds', SettingType::Int, 600);
    }

    /** @return SettingDef<int> */
    public static function rateApiWriteLimit(): SettingDef
    {
        return new SettingDef('rateApiWriteLimit', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function rateApiWriteIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateApiWriteIntervalSeconds', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function rateMessageSendLimit(): SettingDef
    {
        return new SettingDef('rateMessageSendLimit', SettingType::Int, 30);
    }

    /** @return SettingDef<int> */
    public static function ratePasswordResetLimit(): SettingDef
    {
        return new SettingDef('ratePasswordResetLimit', SettingType::Int, 3);
    }

    /** @return SettingDef<int> */
    public static function ratePasswordResetIntervalSeconds(): SettingDef
    {
        return new SettingDef('ratePasswordResetIntervalSeconds', SettingType::Int, 3600);
    }

    /** @return SettingDef<int> */
    public static function rateMessageSendIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateMessageSendIntervalSeconds', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function rateAttachmentUploadLimit(): SettingDef
    {
        return new SettingDef('rateAttachmentUploadLimit', SettingType::Int, 20);
    }

    /** @return SettingDef<int> */
    public static function rateAttachmentUploadIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateAttachmentUploadIntervalSeconds', SettingType::Int, 3600);
    }

    /** @return SettingDef<int> */
    public static function rateSearchLimit(): SettingDef
    {
        return new SettingDef('rateSearchLimit', SettingType::Int, 30);
    }

    /** @return SettingDef<int> */
    public static function rateSearchIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateSearchIntervalSeconds', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function rateTwoFactorLimit(): SettingDef
    {
        return new SettingDef('rateTwoFactorLimit', SettingType::Int, 5);
    }

    /** @return SettingDef<int> */
    public static function rateTwoFactorIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateTwoFactorIntervalSeconds', SettingType::Int, 900);
    }

    /** @return SettingDef<int> */
    public static function rateReportLimit(): SettingDef
    {
        return new SettingDef('rateReportLimit', SettingType::Int, 10);
    }

    /** @return SettingDef<int> */
    public static function rateReportIntervalSeconds(): SettingDef
    {
        return new SettingDef('rateReportIntervalSeconds', SettingType::Int, 3600);
    }

    /** @return SettingDef<?string> */
    public static function smtpHost(): SettingDef
    {
        return new SettingDef('smtpHost', SettingType::String, null, normalizer: self::emptyToNull());
    }

    /** @return SettingDef<?int> */
    public static function smtpPort(): SettingDef
    {
        return new SettingDef('smtpPort', SettingType::Int, null);
    }

    /** @return SettingDef<?string> */
    public static function smtpUsername(): SettingDef
    {
        return new SettingDef('smtpUsername', SettingType::String, null, normalizer: self::emptyToNull());
    }

    /** @return SettingDef<?string> */
    public static function smtpPassword(): SettingDef
    {
        return new SettingDef('smtpPassword', SettingType::String, null, true);
    }

    /** @return SettingDef<?SmtpEncryption> */
    public static function smtpEncryption(): SettingDef
    {
        return new SettingDef('smtpEncryption', SettingType::Enum, null, false, SmtpEncryption::class);
    }

    /** @return SettingDef<?string> */
    public static function smtpFromEmail(): SettingDef
    {
        return new SettingDef('smtpFromEmail', SettingType::String, null, normalizer: self::emptyToNull());
    }

    /** @return SettingDef<?string> */
    public static function smtpFromName(): SettingDef
    {
        return new SettingDef('smtpFromName', SettingType::String, null, normalizer: self::emptyToNull());
    }

    /** @return SettingDef<int> */
    public static function webhookLogRetentionDays(): SettingDef
    {
        return new SettingDef('webhookLogRetentionDays', SettingType::Int, 30);
    }

    /** @return SettingDef<int> */
    public static function webhookMaxQueued(): SettingDef
    {
        return new SettingDef('webhookMaxQueued', SettingType::Int, 1000);
    }

    /** @return SettingDef<bool> */
    public static function webhookAllowInternalUrls(): SettingDef
    {
        return new SettingDef('webhookAllowInternalUrls', SettingType::Bool, false);
    }

    /** @return SettingDef<?\DateTimeImmutable> */
    public static function adminOnboardedAt(): SettingDef
    {
        return new SettingDef('adminOnboardedAt', SettingType::DateTime, null);
    }

    /** @return SettingDef<int> */
    public static function avatarMaxSizeMb(): SettingDef
    {
        return new SettingDef('avatarMaxSizeMb', SettingType::Int, 1);
    }

    /** @return SettingDef<int> */
    public static function avatarMaxWidth(): SettingDef
    {
        return new SettingDef('avatarMaxWidth', SettingType::Int, 1000);
    }

    /** @return SettingDef<int> */
    public static function avatarMaxHeight(): SettingDef
    {
        return new SettingDef('avatarMaxHeight', SettingType::Int, 1000);
    }

    /** @return SettingDef<int> */
    public static function logoMaxSizeMb(): SettingDef
    {
        return new SettingDef('logoMaxSizeMb', SettingType::Int, 1);
    }

    /** @return SettingDef<int> */
    public static function logoMaxWidth(): SettingDef
    {
        return new SettingDef('logoMaxWidth', SettingType::Int, 1000);
    }

    /** @return SettingDef<int> */
    public static function logoMaxHeight(): SettingDef
    {
        return new SettingDef('logoMaxHeight', SettingType::Int, 1000);
    }

    /** @return SettingDef<int> */
    public static function communityEmojiMaxSizeMb(): SettingDef
    {
        return new SettingDef('communityEmojiMaxSizeMb', SettingType::Int, 1);
    }

    /** @return SettingDef<int> */
    public static function communityEmojiMaxWidth(): SettingDef
    {
        return new SettingDef('communityEmojiMaxWidth', SettingType::Int, 2048);
    }

    /** @return SettingDef<int> */
    public static function communityEmojiMaxHeight(): SettingDef
    {
        return new SettingDef('communityEmojiMaxHeight', SettingType::Int, 2048);
    }

    /** @return SettingDef<string> */
    public static function attachmentAllowedMimes(): SettingDef
    {
        return new SettingDef('attachmentAllowedMimes', SettingType::String, 'image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,video/mp4,application/zip,audio/mpeg,audio/wav');
    }

    /** @return SettingDef<string> */
    public static function communityEmojiAllowedMimes(): SettingDef
    {
        return new SettingDef('communityEmojiAllowedMimes', SettingType::String, 'image/png,image/webp,image/gif,image/jpeg');
    }

    /** @return SettingDef<bool> */
    public static function autoTimeoutEnabled(): SettingDef
    {
        return new SettingDef('autoTimeoutEnabled', SettingType::Bool, true);
    }

    /** @return SettingDef<int> */
    public static function autoTimeoutHits(): SettingDef
    {
        return new SettingDef('autoTimeoutHits', SettingType::Int, 5);
    }

    /** @return SettingDef<int> */
    public static function autoTimeoutWindowSeconds(): SettingDef
    {
        return new SettingDef('autoTimeoutWindowSeconds', SettingType::Int, 300);
    }

    /** @return SettingDef<int> */
    public static function autoTimeoutDurationSeconds(): SettingDef
    {
        return new SettingDef('autoTimeoutDurationSeconds', SettingType::Int, 600);
    }

    /** @return SettingDef<bool> */
    public static function autoTimeoutProgressive(): SettingDef
    {
        return new SettingDef('autoTimeoutProgressive', SettingType::Bool, true);
    }

    /** @return SettingDef<int> */
    public static function autoTimeoutResetSeconds(): SettingDef
    {
        return new SettingDef('autoTimeoutResetSeconds', SettingType::Int, 36000);
    }

    /** @return SettingDef<int> */
    public static function autoTimeoutMaxSeconds(): SettingDef
    {
        return new SettingDef('autoTimeoutMaxSeconds', SettingType::Int, 86400);
    }

    /** @return SettingDef<bool> */
    public static function ipReputationEnabled(): SettingDef
    {
        return new SettingDef('ipReputationEnabled', SettingType::Bool, false);
    }

    /** @return SettingDef<string> */
    public static function ipReputationEndpoint(): SettingDef
    {
        return new SettingDef('ipReputationEndpoint', SettingType::String, 'https://europe.stopforumspam.org');
    }

    /** @return SettingDef<int> */
    public static function ipReputationConfidenceMin(): SettingDef
    {
        return new SettingDef('ipReputationConfidenceMin', SettingType::Int, 75);
    }

    /** @return SettingDef<bool> */
    public static function ipReputationCheckUsername(): SettingDef
    {
        return new SettingDef('ipReputationCheckUsername', SettingType::Bool, false);
    }

    /** @return SettingDef<string> */
    public static function ipReputationAllowlist(): SettingDef
    {
        return new SettingDef('ipReputationAllowlist', SettingType::String, '');
    }

    /** @return SettingDef<string> */
    public static function ipReputationAppealContact(): SettingDef
    {
        return new SettingDef('ipReputationAppealContact', SettingType::String, '');
    }

    /** @return SettingDef<int> */
    public static function resetPasswordCodeExpiryMinutes(): SettingDef
    {
        return new SettingDef('resetPasswordCodeExpiryMinutes', SettingType::Int, 15);
    }

    /** @return SettingDef<int> */
    public static function emailChallengeExpiryMinutes(): SettingDef
    {
        return new SettingDef('emailChallengeExpiryMinutes', SettingType::Int, 60);
    }

    /** @return SettingDef<int> */
    public static function invitationExpiryHours(): SettingDef
    {
        return new SettingDef('invitationExpiryHours', SettingType::Int, 72);
    }

    /** @return SettingDef<int> */
    public static function digestHour(): SettingDef
    {
        return new SettingDef('digestHour', SettingType::Int, 8);
    }

    /** @return SettingDef<bool> */
    public static function validateEmails(): SettingDef
    {
        return new SettingDef('validateEmails', SettingType::Bool, true);
    }

    /** @return SettingDef<int> */
    public static function mediaTokenTtlSeconds(): SettingDef
    {
        return new SettingDef('mediaTokenTtlSeconds', SettingType::Int, 3600);
    }

    /** @return SettingDef<int> */
    public static function httpCachePageTtlSeconds(): SettingDef
    {
        return new SettingDef('httpCachePageTtlSeconds', SettingType::Int, 0);
    }

    /** @return SettingDef<int> */
    public static function httpCachePresenceTtlSeconds(): SettingDef
    {
        return new SettingDef('httpCachePresenceTtlSeconds', SettingType::Int, 0);
    }

    /** @return SettingDef<int> */
    public static function communityEmojiTokenTtlSeconds(): SettingDef
    {
        return new SettingDef('communityEmojiTokenTtlSeconds', SettingType::Int, 604800);
    }

    /** @return SettingDef<int> */
    public static function defaultBotId(): SettingDef
    {
        return new SettingDef('defaultBotId', SettingType::Int, 0);
    }

    /** @return SettingDef<int> */
    public static function welcomeBotId(): SettingDef
    {
        return new SettingDef('welcomeBotId', SettingType::Int, 0);
    }

    /** @return SettingDef<int> */
    public static function autoModeratorBotId(): SettingDef
    {
        return new SettingDef('autoModeratorBotId', SettingType::Int, 0);
    }

    /** @var list<SettingDef<mixed>>|null */
    private static ?array $all = null;

    /** @var array<string, SettingDef<mixed>>|null */
    private static ?array $byKey = null;

    /** @return list<SettingDef<mixed>> */
    public static function all(): array
    {
        return self::$all ??= [
            self::serverName(), self::serverDescription(), self::accentColor(),
            self::registrationEnabled(), self::defaultLocale(),
            self::termsContent(), self::privacyContent(), self::legalContactEmail(),
            self::requireRegistrationConsent(),
            self::listInServerCatalogue(),
            self::minimumAgeYears(),
            self::messageRetentionDays(), self::notificationRetentionDays(),
            self::archivedChannelRetentionDays(),
            self::defaultAttachmentRetentionDays(),
            self::diskPurgeTriggerPercent(), self::diskPurgeTargetPercent(),
            self::diskPurgeMinAgeDays(), self::diskPurgeIncludeDms(),
            self::maxAttachmentSizeMb(),
            self::maxAttachmentsPerMessage(), self::defaultWelcomeChannelName(),
            self::rateLoginLimit(), self::rateLoginIntervalSeconds(),
            self::rateSessionRefreshLimit(), self::rateSessionRefreshIntervalSeconds(),
            self::rateRegisterLimit(), self::rateRegisterIntervalSeconds(),
            self::rateApiWriteLimit(), self::rateApiWriteIntervalSeconds(),
            self::rateMessageSendLimit(), self::rateMessageSendIntervalSeconds(),
            self::ratePasswordResetLimit(), self::ratePasswordResetIntervalSeconds(),
            self::rateAttachmentUploadLimit(), self::rateAttachmentUploadIntervalSeconds(),
            self::rateSearchLimit(), self::rateSearchIntervalSeconds(),
            self::rateTwoFactorLimit(), self::rateTwoFactorIntervalSeconds(),
            self::rateReportLimit(), self::rateReportIntervalSeconds(),
            self::smtpHost(), self::smtpPort(), self::smtpUsername(), self::smtpPassword(),
            self::smtpEncryption(), self::smtpFromEmail(), self::smtpFromName(),
            self::webhookLogRetentionDays(), self::webhookMaxQueued(),
            self::webhookAllowInternalUrls(), self::adminOnboardedAt(),
            self::avatarMaxSizeMb(), self::avatarMaxWidth(), self::avatarMaxHeight(),
            self::logoMaxSizeMb(), self::logoMaxWidth(), self::logoMaxHeight(),
            self::communityEmojiMaxSizeMb(), self::communityEmojiMaxWidth(), self::communityEmojiMaxHeight(),
            self::attachmentAllowedMimes(), self::communityEmojiAllowedMimes(),
            self::autoTimeoutEnabled(), self::autoTimeoutHits(), self::autoTimeoutWindowSeconds(),
            self::autoTimeoutDurationSeconds(), self::autoTimeoutProgressive(), self::autoTimeoutResetSeconds(),
            self::autoTimeoutMaxSeconds(),
            self::ipReputationEnabled(), self::ipReputationEndpoint(), self::ipReputationConfidenceMin(),
            self::ipReputationCheckUsername(), self::ipReputationAllowlist(), self::ipReputationAppealContact(),
            self::resetPasswordCodeExpiryMinutes(), self::emailChallengeExpiryMinutes(),
            self::invitationExpiryHours(),
            self::digestHour(), self::validateEmails(), self::mediaTokenTtlSeconds(),
            self::httpCachePageTtlSeconds(), self::httpCachePresenceTtlSeconds(),
            self::communityEmojiTokenTtlSeconds(), self::defaultBotId(),
            self::welcomeBotId(), self::autoModeratorBotId(),
        ];
    }

    /** @return SettingDef<mixed>|null */
    public static function byKey(string $key): ?SettingDef
    {
        if (null === self::$byKey) {
            $map = [];
            foreach (self::all() as $def) {
                $map[$def->key] = $def;
            }
            self::$byKey = $map;
        }

        return self::$byKey[$key] ?? null;
    }

    /** @return \Closure(mixed): mixed */
    private static function trimmed(): \Closure
    {
        return static fn (mixed $v): mixed => is_string($v) ? trim($v) : $v;
    }

    /** @return \Closure(mixed): mixed "" is the clear-to-null sentinel for nullable-string settings */
    private static function emptyToNull(): \Closure
    {
        return static fn (mixed $v): mixed => '' === $v ? null : $v;
    }
}
