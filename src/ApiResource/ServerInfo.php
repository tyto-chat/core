<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\ServerInfo\CommunityStatsDto;
use App\Dto\ServerInfo\UploadConstraintsDto;
use App\Entity\Community;
use App\State\ServerInfo\Provider\ServerInfoProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'Public server identity and client bootstrap configuration.',
    normalizationContext: ['groups' => ['server_info:read', 'community:read']],
    formats: ['json' => ['application/json']],
)]
#[Get(
    uriTemplate: '/server-info',
    provider: ServerInfoProvider::class,
    cacheHeaders: [
        'max_age' => 0,
        'shared_max_age' => 60,
        'vary' => ['Accept', 'Origin'],
    ],
    openapi: new Model\Operation(
        summary: 'Get public server info',
        description: 'Anonymous endpoint. Returns the server\'s identity and everything a client needs to bootstrap: '
            .'service URLs (API, Mercure, LiveKit), feature flags such as `voiceEnabled` and `registrationEnabled`, the '
            .'Web Push VAPID key, upload constraints, legal/registration policy, and the list of public communities. '
            .'Responses are shared-cached for 60 seconds.',
    ),
)]
class ServerInfo
{
    #[Groups(['server_info:read'])]
    public string $name = '';

    #[Groups(['server_info:read'])]
    public string $description = '';

    #[Groups(['server_info:read'])]
    public string $apiUrl = '';

    #[Groups(['server_info:read'])]
    public string $mercureUrl = '';

    #[Groups(['server_info:read'])]
    public string $liveKitUrl = '';

    #[Groups(['server_info:read'])]
    public bool $voiceEnabled = true;

    #[Groups(['server_info:read'])]
    public string $webPushPublicKey = '';

    #[Groups(['server_info:read'])]
    public ?UploadConstraintsDto $uploads = null;

    /** @var Community[] */
    #[Groups(['server_info:read'])]
    public array $communities = [];

    /** @var array<string, CommunityStatsDto> community identifier → public stats */
    #[Groups(['server_info:read'])]
    public array $communityStats = [];

    #[Groups(['server_info:read'])]
    public bool $registrationEnabled = true;

    #[Groups(['server_info:read'])]
    public bool $hasTerms = false;

    #[Groups(['server_info:read'])]
    public bool $hasPrivacy = false;

    #[Groups(['server_info:read'])]
    public bool $requireLegalConsent = false;

    #[Groups(['server_info:read'])]
    public ?string $legalContactEmail = null;

    #[Groups(['server_info:read'])]
    public int $minimumAgeYears = 0;

    #[Groups(['server_info:read'])]
    public int $archivedChannelRetentionDays = 0;

    #[Groups(['server_info:read'])]
    public bool $listInServerCatalogue = false;

    // Default must stay true — upgraded installs without the stamped flag must not bounce admins to /setup.
    #[Groups(['server_info:read'])]
    public bool $adminOnboardingComplete = true;
}
