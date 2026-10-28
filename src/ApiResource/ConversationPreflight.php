<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Conversation\Provider\ConversationPreflightProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ConversationPreflight',
    description: 'What creating a direct conversation with given people would do, without creating it.',
    normalizationContext: ['groups' => ['conversation_preflight:read']],
    operations: [
        new Get(
            uriTemplate: '/conversations/preflight',
            priority: 1,
            security: "is_granted('ROLE_USER')",
            provider: ConversationPreflightProvider::class,
            extraProperties: ['scopeResource' => 'conversations'],
            openapi: new Model\Operation(
                summary: 'Preview the outcome of creating a direct conversation',
                description: 'Runs the same block, shared-community and encryption policy checks as `POST /conversations` '
                    .'and creates nothing. `outcome` is `encrypted`, `plain` (allowed without a warning), `plain_fallback` '
                    .'(allowed only as a plain conversation because the listed users cannot take part in an encrypted one) '
                    .'or `blocked`. `existingIdentifier` names a conversation with these people that creating would return. '
                    .'A block fails with the same `422` as creation. Advisory only: creation re-checks everything.',
                parameters: [
                    new Model\Parameter('memberUserIds[]', 'query', 'Ids of the other participants.', true, false, false, ['type' => 'array', 'items' => ['type' => 'integer']]),
                ],
            ),
        ),
    ],
)]
class ConversationPreflight
{
    /**
     * @param list<int> $refusingUserIds
     * @param list<int> $unreachableUserIds
     */
    public function __construct(
        #[Groups(['conversation_preflight:read'])]
        public string $outcome = 'plain',
        #[Groups(['conversation_preflight:read'])]
        public ?string $reason = null,
        #[Groups(['conversation_preflight:read'])]
        public array $refusingUserIds = [],
        #[Groups(['conversation_preflight:read'])]
        public array $unreachableUserIds = [],
        #[Groups(['conversation_preflight:read'])]
        public ?string $existingIdentifier = null,
    ) {
    }
}
