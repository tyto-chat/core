<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\Dto\Notification\UpdateNotificationDto;
use App\Enum\Notification\NotificationType;
use App\Repository\NotificationRepository;
use App\State\Notification\Processor\UpdateNotificationProcessor;
use App\State\Notification\Provider\DmNotificationProvider;
use App\State\Notification\Provider\NotificationProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'idx_notification_recipient_read', columns: ['recipient_id', 'is_read'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_coalesce_key', columns: ['coalesce_key'])]
#[ApiResource(
    description: 'An in-app notification (mention, reply, DM, moderation notice or coalesced channel activity) addressed to a single recipient.',
    normalizationContext: ['groups' => ['notification:read']],
    mercure: ['topics' => ['@=object.getRecipientNotificationTopic()']],
    paginationItemsPerPage: 30,
    paginationMaximumItemsPerPage: 50,
)]
#[GetCollection(
    uriTemplate: '/communities/{identifier}/notifications',
    uriVariables: [
        'identifier' => new Link(fromClass: Community::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: NotificationProvider::class,
    extraProperties: ['scopeResource' => 'notifications'],
    openapi: new Model\Operation(
        summary: 'List the caller\'s notifications in a community',
        description: 'The authenticated user. Returns only the caller\'s own notifications scoped to '
            .'the given community. `404` when the community does not exist.',
    ),
)]
#[GetCollection(
    uriTemplate: '/me/notifications',
    security: "is_granted('ROLE_USER')",
    provider: DmNotificationProvider::class,
    extraProperties: ['scopeResource' => 'notifications'],
    openapi: new Model\Operation(
        summary: 'List the caller\'s direct-message notifications',
        description: 'The authenticated user. Returns the caller\'s server-wide `dm_message` '
            .'notifications (DMs are not scoped to a community).',
    ),
)]
#[Patch(
    security: "is_granted('NOTIFICATION_UPDATE', object)",
    input: UpdateNotificationDto::class,
    processor: UpdateNotificationProcessor::class,
    extraProperties: ['scopeResource' => 'notifications'],
    openapi: new Model\Operation(
        summary: 'Mark a notification as read',
        description: 'The notification\'s recipient only. Accepts `{"isRead": true}` to mark the '
            .'notification read (which also closes its coalescing window); `false` is a no-op — '
            .'notifications cannot be marked unread.',
    ),
)]
class Notification
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['notification:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $recipient = null;

    #[ORM\ManyToOne(targetEntity: Community::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Community $community = null;

    #[ORM\Column(length: 50, enumType: NotificationType::class)]
    #[Groups(['notification:read'])]
    private NotificationType $type = NotificationType::Mention;

    #[ORM\Column]
    #[Groups(['notification:read', 'notification:update'])]
    private bool $isRead = false;

    /** Must be NULLed on read — the unique index allows many NULLs (MariaDB's stand-in for a partial index), guarding one open coalesced row per key. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coalesceKey = null;

    #[ORM\Column(length: 255)]
    #[Groups(['notification:read'])]
    private string $authorName = '';

    #[ORM\Column(length: 255)]
    #[Groups(['notification:read'])]
    private string $channelIdentifier = '';

    #[ORM\Column(length: 255)]
    #[Groups(['notification:read'])]
    private string $communityIdentifier = '';

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $conversationIdentifier = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $messageIri = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $groupName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $groupIdentifier = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $reason = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['notification:read'])]
    private ?int $moderationActionId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['notification:read'])]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailedAt = null;

    /**
     * @var list<int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['notification:read'])]
    private ?array $actorIds = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    #[Groups(['notification:read'])]
    private int $messageCount = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['notification:read'])]
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(User $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getCommunity(): ?Community
    {
        return $this->community;
    }

    public function setCommunity(?Community $community): static
    {
        $this->community = $community;

        return $this;
    }

    public function getConversationIdentifier(): ?string
    {
        return $this->conversationIdentifier;
    }

    public function setConversationIdentifier(?string $conversationIdentifier): static
    {
        $this->conversationIdentifier = $conversationIdentifier;

        return $this;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function setType(NotificationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getIsRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;
        if ($isRead) {
            $this->coalesceKey = null;
        }

        return $this;
    }

    public function getCoalesceKey(): ?string
    {
        return $this->coalesceKey;
    }

    public function setCoalesceKey(?string $coalesceKey): static
    {
        $this->coalesceKey = $coalesceKey;

        return $this;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $authorName): static
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getChannelIdentifier(): string
    {
        return $this->channelIdentifier;
    }

    public function setChannelIdentifier(string $channelIdentifier): static
    {
        $this->channelIdentifier = $channelIdentifier;

        return $this;
    }

    public function getCommunityIdentifier(): string
    {
        return $this->communityIdentifier;
    }

    public function setCommunityIdentifier(string $communityIdentifier): static
    {
        $this->communityIdentifier = $communityIdentifier;

        return $this;
    }

    public function getMessageIri(): ?string
    {
        return $this->messageIri;
    }

    public function setMessageIri(?string $messageIri): static
    {
        $this->messageIri = $messageIri;

        return $this;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function setGroupName(?string $groupName): static
    {
        $this->groupName = $groupName;

        return $this;
    }

    public function getGroupIdentifier(): ?string
    {
        return $this->groupIdentifier;
    }

    public function setGroupIdentifier(?string $groupIdentifier): static
    {
        $this->groupIdentifier = $groupIdentifier;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getModerationActionId(): ?int
    {
        return $this->moderationActionId;
    }

    public function setModerationActionId(?int $moderationActionId): static
    {
        $this->moderationActionId = $moderationActionId;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getEmailedAt(): ?\DateTimeImmutable
    {
        return $this->emailedAt;
    }

    public function setEmailedAt(?\DateTimeImmutable $emailedAt): static
    {
        $this->emailedAt = $emailedAt;

        return $this;
    }

    /** @return list<int>|null */
    public function getActorIds(): ?array
    {
        return $this->actorIds;
    }

    /** @param list<int>|null $actorIds */
    public function setActorIds(?array $actorIds): static
    {
        $this->actorIds = $actorIds;

        return $this;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $messageCount): static
    {
        $this->messageCount = $messageCount;

        return $this;
    }

    public function getRecipientNotificationTopic(): string
    {
        return '/api/users/'.($this->recipient?->getId() ?? '').'/notifications';
    }
}
