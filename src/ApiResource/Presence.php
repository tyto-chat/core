<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Presence\CommunityOnlineDto;
use App\Dto\Presence\CommunityPresenceSummaryDto;
use App\Dto\Presence\PresenceBatchDto;
use App\Dto\Presence\PresenceHistoryDto;
use App\Dto\Presence\SetManualStatusDto;
use App\Entity\Community;
use App\State\Presence\Processor\MarkOfflineProcessor;
use App\State\Presence\Processor\SetManualStatusProcessor;
use App\State\Presence\Provider\CommunityOnlineProvider;
use App\State\Presence\Provider\CommunityPresenceSummaryProvider;
use App\State\Presence\Provider\PresenceBatchProvider;
use App\State\Presence\Provider\PresenceHistoryProvider;

#[ApiResource(
    shortName: 'Presence',
    description: 'Redis-backed user presence: live activity tracking with an optional manual status override.',
    operations: [
        new Post(
            uriTemplate: '/me/presence',
            security: "is_granted('ROLE_USER')",
            input: SetManualStatusDto::class,
            output: SetManualStatusDto::class,
            normalizationContext: ['groups' => ['presence:read']],
            denormalizationContext: ['groups' => ['presence:write']],
            processor: SetManualStatusProcessor::class,
            openapi: new Model\Operation(
                summary: 'Set manual presence status',
                description: 'Sets or clears the authenticated caller\'s manual status override: `away`, `dnd` or `invisible`; '
                    .'a `null` status clears the override back to automatic live presence. `invisible` is shown to others as '
                    .'`offline`. Echoes the resulting computed `state`.',
            ),
        ),
        new Post(
            uriTemplate: '/me/presence/offline',
            security: "is_granted('ROLE_USER')",
            input: false,
            output: false,
            processor: MarkOfflineProcessor::class,
            openapi: new Model\Operation(
                summary: 'Mark the caller offline immediately',
                description: 'Drops the authenticated caller\'s liveness key so they read as `offline` right away instead of '
                    .'waiting for the activity TTL to lapse. Intended as a tab-close beacon; returns no content.',
            ),
        ),
        new Get(
            uriTemplate: '/presence',
            security: "is_granted('ROLE_USER')",
            output: PresenceBatchDto::class,
            normalizationContext: ['groups' => ['presence:read']],
            provider: PresenceBatchProvider::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Get presence for a batch of users',
                description: 'Returns the computed presence state for each requested user id. Any authenticated user may look '
                    .'up anyone — presence is an app-wide readable signal by design. More than 200 ids yields `422`; '
                    .'non-numeric ids are silently ignored.',
                parameters: [
                    new Model\Parameter('userIds[]', 'query', 'User ids to look up (max 200).', false, false, true, ['type' => 'array', 'items' => ['type' => 'integer']]),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/communities/{identifier}/presence/summary',
            uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
            output: CommunityPresenceSummaryDto::class,
            normalizationContext: ['groups' => ['presence:read']],
            provider: CommunityPresenceSummaryProvider::class,
            extraProperties: ['tyto_http_cache' => 'presence', 'scopeResource' => 'communities'],
            cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
            openapi: new Model\Operation(
                summary: 'Get a community online-member count',
                description: 'Returns how many members of the community are currently online. Public communities are readable '
                    .'anonymously; private ones require membership. Responses are briefly shared-cached, so the count may lag slightly.',
            ),
        ),
        new Get(
            uriTemplate: '/communities/{identifier}/presence/online',
            uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
            security: "is_granted('ROLE_USER')",
            output: CommunityOnlineDto::class,
            normalizationContext: ['groups' => ['presence:read']],
            provider: CommunityOnlineProvider::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'List online members of a community',
                description: 'Returns the user id and presence state of every community member currently counted as online. '
                    .'Requires authentication.',
            ),
        ),
        new Get(
            uriTemplate: '/communities/{identifier}/presence/history',
            uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
            output: PresenceHistoryDto::class,
            normalizationContext: ['groups' => ['presence:read']],
            provider: PresenceHistoryProvider::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Get sampled presence history for a community',
                description: 'Returns 15-minute presence samples (online members and anonymous guests) for the last `days` days (1-90, default 7). Requires global admin or community admin; others receive 404.',
                parameters: [
                    new Model\Parameter('days', 'query', 'Window size in days (1-90, default 7).', false, false, true, ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
class Presence
{
}
