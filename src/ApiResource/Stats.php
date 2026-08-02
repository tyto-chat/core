<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\Stats\CommunityStatsDto;
use App\Dto\Stats\SystemInfoDto;
use App\State\Stats\Provider\StatsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'Server-wide usage and system statistics for administrators.',
    normalizationContext: ['groups' => ['stats:read']],
    formats: ['json' => ['application/json']],
)]
#[Get(
    uriTemplate: '/stats',
    security: "is_granted('ROLE_ADMIN')",
    provider: StatsProvider::class,
    extraProperties: ['scope' => 'admin'],
    openapi: new Model\Operation(
        summary: 'Get server usage statistics',
        description: 'Global-admin only (`ROLE_ADMIN`). Returns host system metrics (CPU load, memory, disk, media size) '
            .'plus per-community usage: channel, member, message and attachment counts and total attachment size.',
    ),
)]
class Stats
{
    #[Groups(['stats:read'])]
    public ?SystemInfoDto $system = null;

    /** @var CommunityStatsDto[] */
    #[Groups(['stats:read'])]
    public array $communities = [];
}
