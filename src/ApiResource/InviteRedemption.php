<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\State\Invite\Processor\AcceptInviteProcessor;
use App\State\Invite\Provider\InvitePreviewProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'Token-keyed community-invite preview and redemption.',
    normalizationContext: ['groups' => ['invite_redemption:read']],
    operations: [
        new Get(
            uriTemplate: '/invites/{token}',
            security: "is_granted('ROLE_USER')",
            provider: InvitePreviewProvider::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Preview a community invite',
                description: 'Resolves an opaque invite token to the target community\'s public identity (identifier and '
                    .'name). Requires authentication but no prior access to the community. `404` for unknown tokens, `410` '
                    .'when the invite has expired or its use limit is exhausted.',
            ),
        ),
        new Post(
            uriTemplate: '/invites/{token}/accept',
            security: "is_granted('ROLE_USER')",
            read: false,
            input: false,
            processor: AcceptInviteProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Accept a community invite',
                description: 'Joins the authenticated caller to the invite\'s community, consuming one use. Idempotent for '
                    .'existing members — no use is burned. `404` for unknown tokens, `410` when the invite has expired or '
                    .'its use limit is exhausted.',
            ),
        ),
    ],
)]
class InviteRedemption
{
    #[ApiProperty(identifier: true)]
    public string $token = '';

    #[Groups(['invite_redemption:read'])]
    public string $communityIdentifier = '';

    #[Groups(['invite_redemption:read'])]
    public string $communityName = '';
}
