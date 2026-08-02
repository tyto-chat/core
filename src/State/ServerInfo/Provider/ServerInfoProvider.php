<?php

declare(strict_types=1);

namespace App\State\ServerInfo\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ServerInfo;
use App\Dto\ServerInfo\UploadConstraintsDto;
use App\Enum\Legal\LegalDocumentType;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Legal\LegalDocumentServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;

/**
 * @implements ProviderInterface<ServerInfo>
 */
final readonly class ServerInfoProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private SettingsServiceInterface $settings,
        private LegalDocumentServiceInterface $legalDocumentService,
        private string $serverApiUrl,
        private string $mercurePublicUrl,
        private string $liveKitPublicUrl,
        private bool $voiceEnabled,
        private string $webPushPublicKey,
        private int $communityEmojiMaxWidth,
        private int $communityEmojiMaxHeight,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ServerInfo
    {
        $dto = new ServerInfo();
        $dto->name = $this->settings->get(Settings::serverName());
        $dto->description = $this->settings->get(Settings::serverDescription());
        $dto->registrationEnabled = $this->settings->get(Settings::registrationEnabled());
        $dto->hasTerms = '' !== $this->legalDocumentService->getContent(LegalDocumentType::Terms);
        $dto->hasPrivacy = '' !== $this->legalDocumentService->getContent(LegalDocumentType::Privacy);
        $dto->requireLegalConsent = $this->settings->get(Settings::requireRegistrationConsent());
        $dto->legalContactEmail = $this->emptyToNull($this->settings->get(Settings::legalContactEmail()));
        $dto->minimumAgeYears = $this->settings->get(Settings::minimumAgeYears());
        $dto->archivedChannelRetentionDays = $this->settings->get(Settings::archivedChannelRetentionDays());
        $dto->listInServerCatalogue = $this->settings->get(Settings::listInServerCatalogue());
        $dto->adminOnboardingComplete = $this->settings->isAdminOnboarded();
        $dto->apiUrl = $this->serverApiUrl;
        $dto->mercureUrl = $this->mercurePublicUrl;
        $dto->liveKitUrl = $this->liveKitPublicUrl;
        $dto->voiceEnabled = $this->voiceEnabled;
        $dto->webPushPublicKey = $this->webPushPublicKey;
        $dto->uploads = new UploadConstraintsDto(
            avatarMaxSize: $this->settings->get(Settings::avatarMaxSizeMb()).'M',
            avatarMaxWidth: $this->settings->get(Settings::avatarMaxWidth()),
            avatarMaxHeight: $this->settings->get(Settings::avatarMaxHeight()),
            logoMaxSize: $this->settings->get(Settings::logoMaxSizeMb()).'M',
            logoMaxWidth: $this->settings->get(Settings::logoMaxWidth()),
            logoMaxHeight: $this->settings->get(Settings::logoMaxHeight()),
            attachmentMaxSize: $this->settings->get(Settings::maxAttachmentSizeMb()).'M',
            attachmentAllowedMimes: $this->settings->get(Settings::attachmentAllowedMimes()),
            attachmentMaxPerMessage: $this->settings->get(Settings::maxAttachmentsPerMessage()),
            communityEmojiMaxSize: $this->settings->get(Settings::communityEmojiMaxSizeMb()).'M',
            communityEmojiMaxWidth: $this->communityEmojiMaxWidth,
            communityEmojiMaxHeight: $this->communityEmojiMaxHeight,
            communityEmojiAllowedMimes: $this->settings->get(Settings::communityEmojiAllowedMimes()),
        );
        $dto->communities = $this->communityService->getPublic();
        $dto->communityStats = $this->communityService->getPublicStats();

        return $dto;
    }

    private function emptyToNull(?string $value): ?string
    {
        return null !== $value && '' !== trim($value) ? $value : null;
    }
}
