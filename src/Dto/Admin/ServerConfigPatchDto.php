<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\Settings\SmtpEncryption;
use App\Enum\Settings\SupportedLocale;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// No property defaults — the service distinguishes "omitted" from an explicit value via uninitialised typed props.
class ServerConfigPatchDto
{
    #[Assert\Length(min: 1, max: 100)]
    #[Groups(['admin_server_config:write'])]
    public string $serverName;

    #[Assert\Length(max: 2000)]
    #[Groups(['admin_server_config:write'])]
    public string $serverDescription;

    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/', message: 'accentColor must be a #RRGGBB hex color.')]
    #[Groups(['admin_server_config:write'])]
    public ?string $accentColor;

    #[Groups(['admin_server_config:write'])]
    public bool $registrationEnabled;

    #[Assert\Length(max: 100000)]
    #[Groups(['admin_server_config:write'])]
    public string $termsContent;

    #[Assert\Length(max: 100000)]
    #[Groups(['admin_server_config:write'])]
    public string $privacyContent;

    #[Assert\Length(max: 255)]
    #[Assert\Email]
    #[Groups(['admin_server_config:write'])]
    public ?string $legalContactEmail;

    #[Groups(['admin_server_config:write'])]
    public bool $requireRegistrationConsent;

    #[Groups(['admin_server_config:write'])]
    public bool $listInServerCatalogue;

    #[Assert\Range(min: 0, max: 25)]
    #[Groups(['admin_server_config:write'])]
    public int $minimumAgeYears;

    #[Assert\Range(min: 0, max: 3650)]
    #[Groups(['admin_server_config:write'])]
    public int $messageRetentionDays;

    #[Assert\Range(min: 0, max: 3650)]
    #[Groups(['admin_server_config:write'])]
    public int $archivedChannelRetentionDays;

    #[Assert\Range(min: 0, max: 3650)]
    #[Groups(['admin_server_config:write'])]
    public int $notificationRetentionDays;

    #[Groups(['admin_server_config:write'])]
    public SupportedLocale $defaultLocale;

    #[Assert\Range(min: 1, max: 3650)]
    #[Groups(['admin_server_config:write'])]
    public ?int $defaultAttachmentRetentionDays;

    #[Assert\Range(min: 0, max: 90)]
    #[Groups(['admin_server_config:write'])]
    public int $diskPurgeTriggerPercent;

    #[Assert\Range(min: 1, max: 95)]
    #[Groups(['admin_server_config:write'])]
    public int $diskPurgeTargetPercent;

    #[Assert\Range(min: 0, max: 3650)]
    #[Groups(['admin_server_config:write'])]
    public int $diskPurgeMinAgeDays;

    #[Groups(['admin_server_config:write'])]
    public bool $diskPurgeIncludeDms;

    #[Assert\Callback]
    public function validateDiskPurgeThresholds(ExecutionContextInterface $context): void
    {
        if (!isset($this->diskPurgeTriggerPercent, $this->diskPurgeTargetPercent)) {
            return;
        }
        if ($this->diskPurgeTriggerPercent > 0 && $this->diskPurgeTargetPercent <= $this->diskPurgeTriggerPercent) {
            $context->buildViolation('The free-space target must be greater than the trigger threshold.')
                ->atPath('diskPurgeTargetPercent')
                ->addViolation();
        }
    }

    #[Assert\Range(min: 1, max: 1024)]
    #[Groups(['admin_server_config:write'])]
    public int $maxAttachmentSizeMb;

    #[Assert\Range(min: 1, max: 50)]
    #[Groups(['admin_server_config:write'])]
    public int $maxAttachmentsPerMessage;

    #[Assert\Length(min: 1, max: 100)]
    #[Groups(['admin_server_config:write'])]
    public string $defaultWelcomeChannelName;

    #[Assert\Range(min: 1, max: 1000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateLoginLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateLoginIntervalSeconds;

    #[Assert\Range(min: 1, max: 1000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateSessionRefreshLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateSessionRefreshIntervalSeconds;

    #[Assert\Range(min: 1, max: 1000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateRegisterLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateRegisterIntervalSeconds;

    #[Assert\Range(min: 1, max: 10000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateApiWriteLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateApiWriteIntervalSeconds;

    #[Assert\Range(min: 1, max: 10000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateMessageSendLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateMessageSendIntervalSeconds;

    #[Assert\Range(min: 1, max: 1000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateAttachmentUploadLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateAttachmentUploadIntervalSeconds;

    #[Assert\Range(min: 1, max: 10000)]
    #[Groups(['admin_server_config:write'])]
    public int $rateSearchLimit;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $rateSearchIntervalSeconds;

    #[Assert\Length(max: 255)]
    #[Groups(['admin_server_config:write'])]
    public ?string $smtpHost;

    #[Assert\Range(min: 1, max: 65535)]
    #[Groups(['admin_server_config:write'])]
    public ?int $smtpPort;

    #[Assert\Length(max: 255)]
    #[Groups(['admin_server_config:write'])]
    public ?string $smtpUsername;

    // Write-only: omit/blank keeps the stored secret.
    #[Assert\Length(max: 1024)]
    #[Groups(['admin_server_config:write'])]
    public ?string $smtpPassword;

    #[Groups(['admin_server_config:write'])]
    public ?SmtpEncryption $smtpEncryption;

    #[Assert\Email]
    #[Groups(['admin_server_config:write'])]
    public ?string $smtpFromEmail;

    #[Assert\Length(max: 255)]
    #[Groups(['admin_server_config:write'])]
    public ?string $smtpFromName;

    #[Assert\Range(min: 1, max: 100)]
    #[Groups(['admin_server_config:write'])]
    public int $avatarMaxSizeMb;

    #[Assert\Range(min: 16, max: 8192)]
    #[Groups(['admin_server_config:write'])]
    public int $avatarMaxWidth;

    #[Assert\Range(min: 16, max: 8192)]
    #[Groups(['admin_server_config:write'])]
    public int $avatarMaxHeight;

    #[Assert\Range(min: 1, max: 100)]
    #[Groups(['admin_server_config:write'])]
    public int $logoMaxSizeMb;

    #[Assert\Range(min: 16, max: 8192)]
    #[Groups(['admin_server_config:write'])]
    public int $logoMaxWidth;

    #[Assert\Range(min: 16, max: 8192)]
    #[Groups(['admin_server_config:write'])]
    public int $logoMaxHeight;

    #[Assert\Range(min: 1, max: 100)]
    #[Groups(['admin_server_config:write'])]
    public int $communityEmojiMaxSizeMb;

    #[Assert\Length(max: 2000)]
    #[Groups(['admin_server_config:write'])]
    public string $attachmentAllowedMimes;

    #[Assert\Length(max: 2000)]
    #[Groups(['admin_server_config:write'])]
    public string $communityEmojiAllowedMimes;

    #[Groups(['admin_server_config:write'])]
    public bool $autoTimeoutEnabled;

    #[Assert\Range(min: 1, max: 1000)]
    #[Groups(['admin_server_config:write'])]
    public int $autoTimeoutHits;

    #[Assert\Range(min: 10, max: 86400)]
    #[Groups(['admin_server_config:write'])]
    public int $autoTimeoutWindowSeconds;

    #[Assert\Range(min: 10, max: 604800)]
    #[Groups(['admin_server_config:write'])]
    public int $autoTimeoutDurationSeconds;

    #[Groups(['admin_server_config:write'])]
    public bool $autoTimeoutProgressive;

    #[Assert\Range(min: 10, max: 604800)]
    #[Groups(['admin_server_config:write'])]
    public int $autoTimeoutResetSeconds;

    #[Assert\Range(min: 60, max: 2592000)]
    #[Groups(['admin_server_config:write'])]
    public int $autoTimeoutMaxSeconds;

    #[Groups(['admin_server_config:write'])]
    public bool $ipReputationEnabled;

    #[Assert\Length(max: 255)]
    #[Groups(['admin_server_config:write'])]
    public string $ipReputationEndpoint;

    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['admin_server_config:write'])]
    public int $ipReputationConfidenceMin;

    #[Groups(['admin_server_config:write'])]
    public bool $ipReputationCheckUsername;

    #[Assert\Length(max: 10000)]
    #[Groups(['admin_server_config:write'])]
    public string $ipReputationAllowlist;

    #[Assert\Length(max: 320)]
    #[Groups(['admin_server_config:write'])]
    public string $ipReputationAppealContact;

    #[Assert\Range(min: 1, max: 1440)]
    #[Groups(['admin_server_config:write'])]
    public int $resetPasswordCodeExpiryMinutes;

    #[Assert\Range(min: 1, max: 1440)]
    #[Groups(['admin_server_config:write'])]
    public int $emailChallengeExpiryMinutes;

    #[Assert\Range(min: 1, max: 720)]
    #[Groups(['admin_server_config:write'])]
    public int $invitationExpiryHours;

    #[Assert\Range(min: 0, max: 23)]
    #[Groups(['admin_server_config:write'])]
    public int $digestHour;

    #[Groups(['admin_server_config:write'])]
    public bool $validateEmails;

    #[Assert\Range(min: 60, max: 2592000)]
    #[Groups(['admin_server_config:write'])]
    public int $mediaTokenTtlSeconds;

    #[Assert\PositiveOrZero]
    #[Groups(['admin_server_config:write'])]
    public int $httpCachePageTtlSeconds;

    #[Assert\PositiveOrZero]
    #[Groups(['admin_server_config:write'])]
    public int $httpCachePresenceTtlSeconds;

    #[Assert\Range(min: 60, max: 31536000)]
    #[Groups(['admin_server_config:write'])]
    public int $communityEmojiTokenTtlSeconds;

    #[Assert\PositiveOrZero]
    #[Groups(['admin_server_config:write'])]
    public int $defaultBotId;

    #[Assert\PositiveOrZero]
    #[Groups(['admin_server_config:write'])]
    public int $welcomeBotId;

    #[Assert\PositiveOrZero]
    #[Groups(['admin_server_config:write'])]
    public int $autoModeratorBotId;
}
