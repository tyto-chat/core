<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Serializer\Attribute\Groups;

class ServerConfigDto
{
    #[Groups(['admin_server_config:read'])]
    public string $serverName = '';

    #[Groups(['admin_server_config:read'])]
    public ?string $serverDescription = null;

    #[Groups(['admin_server_config:read'])]
    public ?string $accentColor = null;

    #[Groups(['admin_server_config:read'])]
    public bool $registrationEnabled = true;

    #[Groups(['admin_server_config:read'])]
    public string $termsContent = '';

    #[Groups(['admin_server_config:read'])]
    public string $privacyContent = '';

    #[Groups(['admin_server_config:read'])]
    public ?string $legalContactEmail = null;

    #[Groups(['admin_server_config:read'])]
    public bool $requireRegistrationConsent = false;

    #[Groups(['admin_server_config:read'])]
    public bool $listInServerCatalogue = false;

    #[Groups(['admin_server_config:read'])]
    public int $minimumAgeYears = 0;

    #[Groups(['admin_server_config:read'])]
    public int $messageRetentionDays = 0;

    #[Groups(['admin_server_config:read'])]
    public int $archivedChannelRetentionDays = 0;

    #[Groups(['admin_server_config:read'])]
    public int $notificationRetentionDays = 0;

    #[Groups(['admin_server_config:read'])]
    public string $defaultLocale = 'en';

    #[Groups(['admin_server_config:read'])]
    public ?int $defaultAttachmentRetentionDays = null;

    #[Groups(['admin_server_config:read'])]
    public int $diskPurgeTriggerPercent = 0;

    #[Groups(['admin_server_config:read'])]
    public int $diskPurgeTargetPercent = 15;

    #[Groups(['admin_server_config:read'])]
    public int $diskPurgeMinAgeDays = 14;

    #[Groups(['admin_server_config:read'])]
    public bool $diskPurgeIncludeDms = false;

    #[Groups(['admin_server_config:read'])]
    public int $maxAttachmentSizeMb = 0;

    #[Groups(['admin_server_config:read'])]
    public int $maxAttachmentsPerMessage = 0;

    #[Groups(['admin_server_config:read'])]
    public string $defaultWelcomeChannelName = '';

    #[Groups(['admin_server_config:read'])]
    public int $rateLoginLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateLoginIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateSessionRefreshLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateSessionRefreshIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateRegisterLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateRegisterIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateApiWriteLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateApiWriteIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateMessageSendLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateMessageSendIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateAttachmentUploadLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateAttachmentUploadIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateSearchLimit = 0;

    #[Groups(['admin_server_config:read'])]
    public int $rateSearchIntervalSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public ?string $smtpHost = null;

    #[Groups(['admin_server_config:read'])]
    public ?int $smtpPort = null;

    #[Groups(['admin_server_config:read'])]
    public ?string $smtpUsername = null;

    #[Groups(['admin_server_config:read'])]
    public bool $smtpPasswordSet = false;

    #[Groups(['admin_server_config:read'])]
    public ?string $smtpEncryption = null;

    #[Groups(['admin_server_config:read'])]
    public ?string $smtpFromEmail = null;

    #[Groups(['admin_server_config:read'])]
    public ?string $smtpFromName = null;

    #[Groups(['admin_server_config:read'])]
    public string $updatedAt = '';

    #[Groups(['admin_server_config:read'])]
    public ?int $updatedBy = null;

    #[Groups(['admin_server_config:read'])]
    public int $avatarMaxSizeMb = 0;

    #[Groups(['admin_server_config:read'])]
    public int $avatarMaxWidth = 0;

    #[Groups(['admin_server_config:read'])]
    public int $avatarMaxHeight = 0;

    #[Groups(['admin_server_config:read'])]
    public int $logoMaxSizeMb = 0;

    #[Groups(['admin_server_config:read'])]
    public int $logoMaxWidth = 0;

    #[Groups(['admin_server_config:read'])]
    public int $logoMaxHeight = 0;

    #[Groups(['admin_server_config:read'])]
    public int $communityEmojiMaxSizeMb = 0;

    #[Groups(['admin_server_config:read'])]
    public string $attachmentAllowedMimes = '';

    #[Groups(['admin_server_config:read'])]
    public string $communityEmojiAllowedMimes = '';

    #[Groups(['admin_server_config:read'])]
    public bool $autoTimeoutEnabled = true;

    #[Groups(['admin_server_config:read'])]
    public int $autoTimeoutHits = 0;

    #[Groups(['admin_server_config:read'])]
    public int $autoTimeoutWindowSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $autoTimeoutDurationSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public bool $autoTimeoutProgressive = true;

    #[Groups(['admin_server_config:read'])]
    public int $autoTimeoutResetSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $autoTimeoutMaxSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public bool $ipReputationEnabled = false;

    #[Groups(['admin_server_config:read'])]
    public string $ipReputationEndpoint = '';

    #[Groups(['admin_server_config:read'])]
    public int $ipReputationConfidenceMin = 0;

    #[Groups(['admin_server_config:read'])]
    public bool $ipReputationCheckUsername = false;

    #[Groups(['admin_server_config:read'])]
    public string $ipReputationAllowlist = '';

    #[Groups(['admin_server_config:read'])]
    public string $ipReputationAppealContact = '';

    #[Groups(['admin_server_config:read'])]
    public int $resetPasswordCodeExpiryMinutes = 0;

    #[Groups(['admin_server_config:read'])]
    public int $emailChallengeExpiryMinutes = 0;

    #[Groups(['admin_server_config:read'])]
    public int $invitationExpiryHours = 0;

    #[Groups(['admin_server_config:read'])]
    public int $digestHour = 0;

    #[Groups(['admin_server_config:read'])]
    public bool $validateEmails = true;

    #[Groups(['admin_server_config:read'])]
    public int $mediaTokenTtlSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $httpCachePageTtlSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $httpCachePresenceTtlSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $communityEmojiTokenTtlSeconds = 0;

    #[Groups(['admin_server_config:read'])]
    public int $defaultBotId = 0;

    #[Groups(['admin_server_config:read'])]
    public int $welcomeBotId = 0;

    #[Groups(['admin_server_config:read'])]
    public int $autoModeratorBotId = 0;

    public static function fromSettings(SettingsServiceInterface $s): self
    {
        $dto = new self();
        $dto->serverName = $s->get(Settings::serverName());
        $dto->serverDescription = $s->get(Settings::serverDescription());
        $dto->accentColor = $s->get(Settings::accentColor());
        $dto->registrationEnabled = $s->get(Settings::registrationEnabled());
        $dto->termsContent = $s->get(Settings::termsContent());
        $dto->privacyContent = $s->get(Settings::privacyContent());
        $dto->legalContactEmail = $s->get(Settings::legalContactEmail());
        $dto->requireRegistrationConsent = $s->get(Settings::requireRegistrationConsent());
        $dto->listInServerCatalogue = $s->get(Settings::listInServerCatalogue());
        $dto->minimumAgeYears = $s->get(Settings::minimumAgeYears());
        $dto->messageRetentionDays = $s->get(Settings::messageRetentionDays());
        $dto->notificationRetentionDays = $s->get(Settings::notificationRetentionDays());
        $dto->archivedChannelRetentionDays = $s->get(Settings::archivedChannelRetentionDays());
        $dto->defaultLocale = $s->get(Settings::defaultLocale())->value;
        $dto->defaultAttachmentRetentionDays = $s->get(Settings::defaultAttachmentRetentionDays());
        $dto->diskPurgeTriggerPercent = $s->get(Settings::diskPurgeTriggerPercent());
        $dto->diskPurgeTargetPercent = $s->get(Settings::diskPurgeTargetPercent());
        $dto->diskPurgeMinAgeDays = $s->get(Settings::diskPurgeMinAgeDays());
        $dto->diskPurgeIncludeDms = $s->get(Settings::diskPurgeIncludeDms());
        $dto->maxAttachmentSizeMb = $s->get(Settings::maxAttachmentSizeMb());
        $dto->maxAttachmentsPerMessage = $s->get(Settings::maxAttachmentsPerMessage());
        $dto->defaultWelcomeChannelName = $s->get(Settings::defaultWelcomeChannelName());
        $dto->rateLoginLimit = $s->get(Settings::rateLoginLimit());
        $dto->rateLoginIntervalSeconds = $s->get(Settings::rateLoginIntervalSeconds());
        $dto->rateSessionRefreshLimit = $s->get(Settings::rateSessionRefreshLimit());
        $dto->rateSessionRefreshIntervalSeconds = $s->get(Settings::rateSessionRefreshIntervalSeconds());
        $dto->rateRegisterLimit = $s->get(Settings::rateRegisterLimit());
        $dto->rateRegisterIntervalSeconds = $s->get(Settings::rateRegisterIntervalSeconds());
        $dto->rateApiWriteLimit = $s->get(Settings::rateApiWriteLimit());
        $dto->rateApiWriteIntervalSeconds = $s->get(Settings::rateApiWriteIntervalSeconds());
        $dto->rateMessageSendLimit = $s->get(Settings::rateMessageSendLimit());
        $dto->rateMessageSendIntervalSeconds = $s->get(Settings::rateMessageSendIntervalSeconds());
        $dto->rateAttachmentUploadLimit = $s->get(Settings::rateAttachmentUploadLimit());
        $dto->rateAttachmentUploadIntervalSeconds = $s->get(Settings::rateAttachmentUploadIntervalSeconds());
        $dto->rateSearchLimit = $s->get(Settings::rateSearchLimit());
        $dto->rateSearchIntervalSeconds = $s->get(Settings::rateSearchIntervalSeconds());
        $dto->smtpHost = $s->get(Settings::smtpHost());
        $dto->smtpPort = $s->get(Settings::smtpPort());
        $dto->smtpUsername = $s->get(Settings::smtpUsername());
        $dto->smtpPasswordSet = $s->has(Settings::smtpPassword());
        $dto->smtpEncryption = $s->get(Settings::smtpEncryption())?->value;
        $dto->smtpFromEmail = $s->get(Settings::smtpFromEmail());
        $dto->smtpFromName = $s->get(Settings::smtpFromName());
        $dto->avatarMaxSizeMb = $s->get(Settings::avatarMaxSizeMb());
        $dto->avatarMaxWidth = $s->get(Settings::avatarMaxWidth());
        $dto->avatarMaxHeight = $s->get(Settings::avatarMaxHeight());
        $dto->logoMaxSizeMb = $s->get(Settings::logoMaxSizeMb());
        $dto->logoMaxWidth = $s->get(Settings::logoMaxWidth());
        $dto->logoMaxHeight = $s->get(Settings::logoMaxHeight());
        $dto->communityEmojiMaxSizeMb = $s->get(Settings::communityEmojiMaxSizeMb());
        $dto->attachmentAllowedMimes = $s->get(Settings::attachmentAllowedMimes());
        $dto->communityEmojiAllowedMimes = $s->get(Settings::communityEmojiAllowedMimes());
        $dto->autoTimeoutEnabled = $s->get(Settings::autoTimeoutEnabled());
        $dto->autoTimeoutHits = $s->get(Settings::autoTimeoutHits());
        $dto->autoTimeoutWindowSeconds = $s->get(Settings::autoTimeoutWindowSeconds());
        $dto->autoTimeoutDurationSeconds = $s->get(Settings::autoTimeoutDurationSeconds());
        $dto->autoTimeoutProgressive = $s->get(Settings::autoTimeoutProgressive());
        $dto->autoTimeoutResetSeconds = $s->get(Settings::autoTimeoutResetSeconds());
        $dto->autoTimeoutMaxSeconds = $s->get(Settings::autoTimeoutMaxSeconds());
        $dto->ipReputationEnabled = $s->get(Settings::ipReputationEnabled());
        $dto->ipReputationEndpoint = $s->get(Settings::ipReputationEndpoint());
        $dto->ipReputationConfidenceMin = $s->get(Settings::ipReputationConfidenceMin());
        $dto->ipReputationCheckUsername = $s->get(Settings::ipReputationCheckUsername());
        $dto->ipReputationAllowlist = $s->get(Settings::ipReputationAllowlist());
        $dto->ipReputationAppealContact = $s->get(Settings::ipReputationAppealContact());
        $dto->resetPasswordCodeExpiryMinutes = $s->get(Settings::resetPasswordCodeExpiryMinutes());
        $dto->emailChallengeExpiryMinutes = $s->get(Settings::emailChallengeExpiryMinutes());
        $dto->invitationExpiryHours = $s->get(Settings::invitationExpiryHours());
        $dto->digestHour = $s->get(Settings::digestHour());
        $dto->validateEmails = $s->get(Settings::validateEmails());
        $dto->mediaTokenTtlSeconds = $s->get(Settings::mediaTokenTtlSeconds());
        $dto->httpCachePageTtlSeconds = $s->get(Settings::httpCachePageTtlSeconds());
        $dto->httpCachePresenceTtlSeconds = $s->get(Settings::httpCachePresenceTtlSeconds());
        $dto->communityEmojiTokenTtlSeconds = $s->get(Settings::communityEmojiTokenTtlSeconds());
        $dto->defaultBotId = $s->get(Settings::defaultBotId());
        $dto->welcomeBotId = $s->get(Settings::welcomeBotId());
        $dto->autoModeratorBotId = $s->get(Settings::autoModeratorBotId());
        $last = $s->lastUpdate();
        $dto->updatedAt = $last['at']?->format(\DateTimeInterface::ATOM) ?? '';
        $dto->updatedBy = $last['by']?->getId();

        return $dto;
    }
}
