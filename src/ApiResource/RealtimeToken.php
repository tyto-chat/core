<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\Realtime\RealtimeTokenDto;
use App\State\Realtime\Provider\PublicRealtimeTokenProvider;
use App\State\Realtime\Provider\RealtimeTokenProvider;

#[ApiResource(
    shortName: 'RealtimeToken',
    description: 'Short-lived Mercure subscriber JWTs authorising real-time (SSE) topic subscriptions.',
    normalizationContext: ['groups' => ['realtime_token:read']],
    operations: [
        new Get(
            uriTemplate: '/realtime/token',
            security: "is_granted('ROLE_USER')",
            output: RealtimeTokenDto::class,
            provider: RealtimeTokenProvider::class,
            openapi: new Model\Operation(
                summary: 'Get a realtime subscriber token',
                description: 'Mints a Mercure subscriber JWT for the caller\'s own topics (notifications, events, conversation '
                    .'activity) and the presence topic template, plus the scopes requested: `community` adds that '
                    .'community\'s structure, activity, emojis and every channel the caller can view in it (with threads and '
                    .'voice participants); `conversation` adds that conversation and its threads. Scopes the caller cannot '
                    .'view are silently left out. The token lifetime is the admin setting `realtimeTokenTtlMinutes` (30 by default): `expiresIn` is that lifetime in '
                    .'seconds, `expiresAt` the expiry as epoch seconds.',
                parameters: [
                    new Model\Parameter('community', 'query', 'Identifier of the community being viewed.', false, false, true, ['type' => 'string']),
                    new Model\Parameter('conversation', 'query', 'Identifier of the conversation being viewed.', false, false, true, ['type' => 'string']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/realtime/public-token',
            output: RealtimeTokenDto::class,
            provider: PublicRealtimeTokenProvider::class,
            openapi: new Model\Operation(
                summary: 'Get an anonymous realtime subscriber token',
                description: 'Anonymous endpoint. Mints a Mercure subscriber JWT for the requested `community`: its public '
                    .'text channels and their threads, plus its structure and emoji topics. `token`, `expiresAt` and `expiresIn` are '
                    .'`null` when the community is missing, not public, or has no public text channels.',
                parameters: [
                    new Model\Parameter('community', 'query', 'Identifier of the community being viewed.', false, false, true, ['type' => 'string']),
                ],
            ),
        ),
    ],
)]
class RealtimeToken
{
}
