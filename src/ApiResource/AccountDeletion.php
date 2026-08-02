<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\State\AccountDeletion\Processor\CancelAccountDeletionProcessor;
use App\State\AccountDeletion\Processor\RequestAccountDeletionProcessor;
use App\State\AccountDeletion\Provider\AccountDeletionStatusProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'Self-service GDPR account-deletion status for the calling user.',
    // skip_null_values keeps the not-pending payload as {"pending":false} with purgeAt omitted — clients depend on this shape.
    normalizationContext: ['groups' => ['account_deletion:read'], 'skip_null_values' => true],
    formats: ['json' => ['application/json']],
    operations: [
        new Get(
            uriTemplate: '/me/account-deletion',
            security: "is_granted('ROLE_USER')",
            provider: AccountDeletionStatusProvider::class,
            openapi: new Model\Operation(
                summary: 'Get account-deletion status',
                description: 'Returns whether the authenticated caller\'s account is scheduled for deletion. While pending, '
                    .'`purgeAt` carries the hard-purge date; otherwise the field is omitted.',
            ),
        ),
        new Post(
            uriTemplate: '/me/account-deletion',
            status: 200,
            security: "is_granted('ROLE_USER')",
            input: false,
            processor: RequestAccountDeletionProcessor::class,
            openapi: new Model\Operation(
                summary: 'Request account deletion',
                description: 'Schedules the authenticated caller\'s account for hard deletion after a 7-day grace period, '
                    .'immediately revoking all API keys and removing all push subscriptions. Returns the pending status '
                    .'with `purgeAt`; `409` when a deletion is already pending.',
            ),
        ),
        new Delete(
            uriTemplate: '/me/account-deletion',
            security: "is_granted('ROLE_USER')",
            read: false,
            processor: CancelAccountDeletionProcessor::class,
            openapi: new Model\Operation(
                summary: 'Cancel a pending account deletion',
                description: 'Cancels the authenticated caller\'s scheduled deletion during the grace period. `409` when no '
                    .'deletion is pending. API keys and push subscriptions revoked at request time are not restored.',
            ),
        ),
    ],
)]
class AccountDeletion
{
    #[Groups(['account_deletion:read'])]
    public bool $pending = false;

    #[Groups(['account_deletion:read'])]
    public ?\DateTimeImmutable $purgeAt = null;
}
