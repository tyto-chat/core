<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\Dto\Message\SendMessageDto;
use App\Dto\Message\UpdateMessageDto;
use App\Enum\Message\MessageKind;
use App\Repository\MessageRepository;
use App\State\Message\Processor\DeleteMessageProcessor;
use App\State\Message\Processor\EditMessageProcessor;
use App\State\Message\Processor\PinMessageProcessor;
use App\State\Message\Processor\ReplyToMessageProcessor;
use App\State\Message\Processor\SendChannelMessageProcessor;
use App\State\Message\Processor\SendConversationMessageProcessor;
use App\State\Message\Processor\UnpinMessageProcessor;
use App\State\Message\Provider\MessageByIdProvider;
use App\State\Message\Provider\PinnedMessagesProvider;
use App\State\Message\Provider\ThreadRepliesProvider;
use App\State\MessagePage\Provider\CurrentChannelMessagePageProvider;
use App\State\MessagePage\Provider\CurrentConversationMessagePageProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(name: 'IDX_MESSAGE_PAGE_PARENT', columns: ['page_id', 'parent_id'])]
#[Gedmo\SoftDeleteable]
#[ApiResource(
    description: 'A chat message. Messages live in fixed-size pages inside a channel or a direct conversation; replies form one-level threads under a root message.',
    normalizationContext: ['groups' => ['message:read']],
    denormalizationContext: ['groups' => ['message:create', 'message:update']],
    mercure: ['topics' => ['@=object.getPublishTopic()'], 'private' => true],
    paginationItemsPerPage: 30,
    paginationMaximumItemsPerPage: 50,
)]
#[Get(
    uriTemplate: '/messages/{id}',
    openapi: new Model\Operation(
        summary: 'Get a message by UUID',
        description: 'Returns a single message with its text hydrated from the latest revision. '
            .'Visible to anyone who may view the container: channel viewers (anonymous access works on public '
            .'channels of public communities) or, for direct messages, participants only. Backs the `/m/{uuid}` '
            .'permalink resolver; soft-deleted messages are still returned with `isDeleted: true` and `text: null`.',
    ),
    security: "is_granted('MESSAGE_VIEW', object)",
    provider: MessageByIdProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'messages'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
)]
#[Delete(
    openapi: new Model\Operation(
        summary: 'Soft-delete a message',
        description: 'Marks the message deleted instead of removing the row: sets `isDeleted: true`, records '
            .'`deletedAt` and `deletedBy`, deletes attachments and publishes a Mercure delete event; the message '
            .'remains readable as a tombstone with `text: null`. Standard messages may be deleted by their author '
            .'or a channel moderator (direct messages: author only); `system` messages only by a community admin '
            .'or global admin. The reply count of a root is not decremented when a reply is deleted.',
    ),
    security: "is_granted('MESSAGE_DELETE', object)",
    processor: DeleteMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/channels/{channel}/messages/current',
    openapi: new Model\Operation(
        summary: 'Get the current message page of a channel',
        description: 'Returns the latest page of the channel with hydrated root messages; thread replies are '
            .'excluded from the timeline. Requires a signed-in caller who may view the channel.',
    ),
    normalizationContext: ['groups' => ['message_page:read', 'message_page:detail', 'message:read']],
    provider: CurrentChannelMessagePageProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'messages'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/channels/{channel}/pinned-messages',
    openapi: new Model\Operation(
        summary: 'List pinned messages of a channel',
        description: 'Returns every currently pinned message of the channel with hydrated text. '
            .'Available to any signed-in caller who may view the channel.',
    ),
    security: "is_granted('ROLE_USER')",
    provider: PinnedMessagesProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'messages'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
)]
#[Post(
    uriTemplate: '/messages/{id}/pin',
    openapi: new Model\Operation(
        summary: 'Pin a message to its channel',
        description: 'Marks a channel message as pinned, recording `pinnedAt`/`pinnedBy` and publishing a Mercure '
            .'pin event to the channel topic. Restricted to moderation-capable users of the channel (channel or '
            .'community moderators and admins). Replies, `system` messages and direct messages cannot be pinned; '
            .'pinning an already-pinned message or exceeding the 50-pin cap also fails — all with `422`.',
    ),
    security: "is_granted('ROLE_USER')",
    read: false,
    deserialize: false,
    processor: PinMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[Delete(
    uriTemplate: '/messages/{id}/pin',
    openapi: new Model\Operation(
        summary: 'Unpin a message',
        description: 'Clears `pinnedAt`/`pinnedBy` and publishes a Mercure pin event to the channel topic. '
            .'Same moderator audience as pinning; fails with `422` when the message is not pinned.',
    ),
    security: "is_granted('ROLE_USER')",
    processor: UnpinMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[Post(
    uriTemplate: '/messages/{id}/replies',
    openapi: new Model\Operation(
        summary: 'Reply in a thread',
        description: 'Creates a one-level thread reply attached to the page of the root message, bumping the '
            .'`replyCount` and `lastReplyAt` of the root. Channel roots require reply access and no active '
            .'timeout; async notifications and a `message.replied` webhook are dispatched. Conversation roots '
            .'are participant-only with no admin bypass; DM notifications are sent synchronously and no webhook '
            .'is emitted. Replying to a reply (`CannotReplyToReply`) or to a `system` root (`ThreadNotAllowed`) '
            .'fails with `422`.',
    ),
    security: "is_granted('ROLE_USER')",
    input: SendMessageDto::class,
    read: false,
    processor: ReplyToMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[GetCollection(
    uriTemplate: '/messages/{id}/thread',
    openapi: new Model\Operation(
        summary: 'List replies of a thread',
        description: 'Returns the replies of a root message sorted by creation time ascending, paged by keyset: '
            .'`limit` (default 50, max 100) plus an optional `before` cursor naming a reply UUID; a cursor that '
            .'is not a reply of this thread yields `404`. Audience matches viewing the root — anonymous on '
            .'public channels is allowed, DM threads are participant-only. Soft-deleted replies are included '
            .'as tombstones.',
    ),
    provider: ThreadRepliesProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'messages'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
)]
#[GetCollection(
    uriTemplate: '/conversations/{conversation}/messages/current',
    uriVariables: [
        'conversation' => new Link(fromClass: Conversation::class, fromProperty: 'pages', identifiers: ['identifier']),
    ],
    openapi: new Model\Operation(
        summary: 'Get the current message page of a conversation',
        description: 'Returns the latest page of the direct conversation with hydrated root messages; thread '
            .'replies are excluded. Participants only — there is no admin bypass for direct messages.',
    ),
    normalizationContext: ['groups' => ['message_page:read', 'message_page:detail', 'message:read']],
    security: "is_granted('ROLE_USER')",
    provider: CurrentConversationMessagePageProvider::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Patch(
    openapi: new Model\Operation(
        summary: 'Edit a message',
        description: 'Appends a new revision to the message body (previous revisions are kept as history), '
            .'re-extracts mentions and publishes a Mercure update. Allowed for the author or, in channels, '
            .'a channel moderator; direct messages are author-only. `system` messages are immutable and can '
            .'never be edited.',
    ),
    security: "is_granted('MESSAGE_UPDATE', object)",
    input: UpdateMessageDto::class,
    processor: EditMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/messages',
    openapi: new Model\Operation(
        summary: 'Send a message to a channel',
        description: 'Creates a message on the current page of the channel, starting a new page once the current '
            .'one holds 50 root messages. Requires post access to the channel and no active timeout; broadcast '
            .'mentions are checked against the community minimum role. Publishes channel activity via Mercure, '
            .'dispatches async mention/activity notifications and emits a `message.created` webhook event.',
    ),
    security: "is_granted('ROLE_USER')",
    input: SendMessageDto::class,
    processor: SendChannelMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[Post(
    uriTemplate: '/conversations/{conversation}/messages',
    uriVariables: [
        'conversation' => new Link(fromClass: Conversation::class, fromProperty: 'pages', identifiers: ['identifier']),
    ],
    openapi: new Model\Operation(
        summary: 'Send a direct message',
        description: 'Creates a message on the current page of the conversation and bumps `lastMessageAt`. '
            .'Participants only — no admin bypass. Publishes conversation activity via Mercure and dispatches '
            .'DM notifications synchronously; direct-message content never reaches webhooks.',
    ),
    security: "is_granted('ROLE_USER')",
    input: SendMessageDto::class,
    read: false,
    processor: SendConversationMessageProcessor::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Put(
    openapi: new Model\Operation(
        summary: 'Replace the text of a message',
        description: 'Same behaviour and audience as the `PATCH` edit: appends a new body revision, keeps the '
            .'edit history and publishes a Mercure update. `system` messages are immutable.',
    ),
    security: "is_granted('MESSAGE_UPDATE', object)",
    processor: EditMessageProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
class Message
{
    use BlameableEntity;
    use TimestampableEntity;
    use SoftDeleteableEntity;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** @var array<string, list<array{id: int, userId: int}>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['message:read'])]
    private ?array $reactions = null;

    /** @var int[]|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $mentionedUserIds = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[Groups(['message:read'])]
    private ?self $parent = null;

    /**
     * @var Collection<int, MessageRevision>
     */
    #[ORM\OneToMany(targetEntity: MessageRevision::class, mappedBy: 'message', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $revisions;

    // See Community::$private for the serializer prefix constraint.
    #[ORM\Column(name: 'is_deleted', options: ['default' => false])]
    #[Groups(['message:read'])]
    #[SerializedName('isDeleted')]
    private bool $deleted = false;

    #[ORM\Column(length: 16, enumType: MessageKind::class, options: ['default' => 'standard'])]
    #[Groups(['message:read'])]
    private MessageKind $kind = MessageKind::Standard;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['message:read'])]
    private ?User $deletedBy = null;

    #[Groups(['message:create', 'message:update', 'message:read'])]
    private ?string $text = null;

    /** @var \DateTimeInterface|null */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['message:read'])]
    protected $createdAt;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['message:read'])]
    protected $createdBy;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'update')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected $updatedBy;

    #[ORM\ManyToOne(targetEntity: MessagePage::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?MessagePage $page = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['message:read'])]
    private ?\DateTimeImmutable $pinnedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['message:read'])]
    private ?User $pinnedBy = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['message:read'])]
    private int $replyCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['message:read'])]
    private ?\DateTimeImmutable $lastReplyAt = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['message:read'])]
    private int $purgedAttachmentCount = 0;

    /**
     * @var Collection<int, MediaObject>
     */
    #[ORM\OneToMany(targetEntity: MediaObject::class, mappedBy: 'message')]
    #[Groups(['message:read'])]
    private Collection $attachments;

    public function __construct()
    {
        $this->id = Uuid::v4()->toString();
        $this->revisions = new ArrayCollection();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** @return array<string, list<array{id: int, userId: int}>>|null */
    public function getReactions(): ?array
    {
        return $this->reactions;
    }

    /** @param array<string, list<array{id: int, userId: int}>>|null $reactions */
    public function setReactions(?array $reactions): static
    {
        $this->reactions = $reactions;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Collection<int, MessageRevision>
     */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function addRevision(MessageRevision $revision): static
    {
        if (!$this->revisions->contains($revision)) {
            $this->revisions->add($revision);
            $revision->setMessage($this);
        }

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    #[Groups(['message:read'])]
    public function isEdited(): bool
    {
        return $this->revisions->count() > 1;
    }

    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function getKind(): MessageKind
    {
        return $this->kind;
    }

    public function setKind(MessageKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * The SQL soft-delete filter is intentionally NOT registered — deleted rows stay queryable.
     *
     * @return \DateTime|null
     */
    #[Groups(['message:read'])]
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }

    public function setDeletedBy(?User $deletedBy): static
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->isDeleted() ? null : $this->text;
    }

    public function setText(?string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getPage(): ?MessagePage
    {
        return $this->page;
    }

    public function setPage(?MessagePage $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPinnedAt(): ?\DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function setPinnedAt(?\DateTimeImmutable $pinnedAt): static
    {
        $this->pinnedAt = $pinnedAt;

        return $this;
    }

    public function getPinnedBy(): ?User
    {
        return $this->pinnedBy;
    }

    public function setPinnedBy(?User $pinnedBy): static
    {
        $this->pinnedBy = $pinnedBy;

        return $this;
    }

    #[Groups(['message:read'])]
    public function isPinned(): bool
    {
        return null !== $this->pinnedAt && !$this->deleted;
    }

    public function getReplyCount(): int
    {
        return $this->replyCount;
    }

    public function incrementReplyCount(): static
    {
        ++$this->replyCount;

        return $this;
    }

    public function getLastReplyAt(): ?\DateTimeImmutable
    {
        return $this->lastReplyAt;
    }

    public function setLastReplyAt(?\DateTimeImmutable $lastReplyAt): static
    {
        $this->lastReplyAt = $lastReplyAt;

        return $this;
    }

    public function getPurgedAttachmentCount(): int
    {
        return $this->purgedAttachmentCount;
    }

    public function setPurgedAttachmentCount(int $purgedAttachmentCount): static
    {
        $this->purgedAttachmentCount = $purgedAttachmentCount;

        return $this;
    }

    /** @return int[] */
    public function getMentionedUserIds(): array
    {
        return $this->mentionedUserIds ?? [];
    }

    /** @param int[] $ids */
    public function setMentionedUserIds(array $ids): static
    {
        $this->mentionedUserIds = $ids ?: null;

        return $this;
    }

    public function getChannel(): ?Channel
    {
        return $this->page?->getChannel();
    }

    public function getConversation(): ?Conversation
    {
        return $this->page?->getConversation();
    }

    #[Groups(['message:read'])]
    public function getPageNumber(): ?int
    {
        return $this->page?->getPageNumber();
    }

    #[Groups(['message:read'])]
    public function getCommunityIdentifier(): ?string
    {
        return $this->getChannel()?->getCommunity()?->getIdentifier();
    }

    #[Groups(['message:read'])]
    public function getChannelIdentifier(): ?string
    {
        return $this->getChannel()?->getIdentifier();
    }

    #[Groups(['message:read'])]
    public function getConversationIdentifier(): ?string
    {
        return $this->getConversation()?->getIdentifier();
    }

    public function getChannelIri(): string
    {
        $channel = $this->getChannel();
        if (!$channel) {
            return '';
        }
        $communityIdentifier = $channel->getCommunity()?->getIdentifier() ?? '';

        return '/api/communities/'.$communityIdentifier.'/channels/'.$channel->getIdentifier();
    }

    public function getConversationIri(): string
    {
        $conversation = $this->getConversation();
        if (!$conversation) {
            return '';
        }

        return '/api/conversations/'.$conversation->getIdentifier();
    }

    public function getContainerIri(): string
    {
        return $this->getChannelIri() ?: $this->getConversationIri();
    }

    public function getThreadTopic(): string
    {
        $root = $this->parent;
        if (null === $root) {
            return '';
        }

        $containerIri = $root->getContainerIri();

        return '' !== $containerIri ? $containerIri.'/messages/'.$root->getId().'/thread' : '';
    }

    /** Must return a single string (array crashes Mercure's QueryBuilder); replies publish only to the thread topic, never the timeline. */
    public function getPublishTopic(): string
    {
        return null !== $this->parent ? $this->getThreadTopic() : $this->getContainerIri();
    }

    /**
     * @return Collection<int, MediaObject>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }
}
