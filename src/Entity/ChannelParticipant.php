<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Channel\Provider\ChannelParticipantsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'A user currently connected to a channel\'s voice room.',
    normalizationContext: ['groups' => ['channel_participant:read']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/channels/{channel}/participants',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ChannelParticipantsProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List voice call participants',
        description: 'Community members only. Returns the users currently connected to the channel\'s '
            .'voice room, with their LiveKit identity and join time.',
    ),
)]
final readonly class ChannelParticipant
{
    public function __construct(
        private User $user,
        private Channel $channel,
        private string $livekitIdentity,
        private \DateTimeImmutable $joinedAt,
    ) {
    }

    #[Groups(['channel_participant:read'])]
    public function getId(): ?int
    {
        return $this->user->getId();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    #[Groups(['channel_participant:read'])]
    public function getLivekitIdentity(): string
    {
        return $this->livekitIdentity;
    }

    #[Groups(['channel_participant:read'])]
    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    #[Groups(['channel_participant:read'])]
    public function getUserId(): ?int
    {
        return $this->user->getId();
    }

    #[Groups(['channel_participant:read'])]
    public function getProfile(): ?Profile
    {
        return $this->user->getProfile();
    }
}
