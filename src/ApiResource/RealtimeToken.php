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
                description: 'Mints a Mercure subscriber JWT covering every topic the authenticated caller may read: viewable '
                    .'channels and their threads, community activity and structure, their notifications and conversations, '
                    .'plus the presence topic template. The token expires after one hour (`expiresAt` is epoch seconds).',
            ),
        ),
        new Get(
            uriTemplate: '/realtime/public-token',
            output: RealtimeTokenDto::class,
            provider: PublicRealtimeTokenProvider::class,
            openapi: new Model\Operation(
                summary: 'Get an anonymous realtime subscriber token',
                description: 'Anonymous endpoint. Mints a Mercure subscriber JWT limited to public text channels, their '
                    .'communities\' structure and emoji topics, and public-channel threads. Both `token` and `expiresAt` are '
                    .'`null` when the server has no public text channels.',
            ),
        ),
    ],
)]
class RealtimeToken
{
}
