<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Conversation\CreateConversationDto;
use App\Dto\Conversation\MuteConversationDto;
use App\Repository\ConversationRepository;
use App\State\Conversation\Processor\CreateConversationProcessor;
use App\State\Conversation\Processor\MarkReadConversationProcessor;
use App\State\Conversation\Processor\MuteConversationProcessor;
use App\State\Conversation\Processor\PublishConversationTypingProcessor;
use App\State\Conversation\Provider\ConversationProvider;
use App\State\Conversation\Provider\ConversationsProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
#[ORM\UniqueConstraint(name: 'UNIQ_CONVERSATION_IDENTIFIER', fields: ['identifier'])]
#[ORM\UniqueConstraint(name: 'UNIQ_CONVERSATION_PARTICIPANTS_HASH', fields: ['participantsHash'])]
#[ApiResource(
    description: 'A direct-message conversation between a fixed set of users; the participant set is immutable and has at most one conversation.',
    normalizationContext: ['groups' => ['conversation:read']],
    denormalizationContext: ['groups' => ['conversation:create', 'conversation:mute']],
    mercure: ['topics' => ['@=object.getConversationIri()'], 'private' => true],
)]
#[GetCollection(
    uriTemplate: '/conversations',
    openapi: new Model\Operation(
        summary: 'List the conversations of the current user',
        description: 'Returns every conversation the caller participates in, each with a caller-scoped '
            .'`unreadCount` derived from the last-read timestamp of the caller.',
    ),
    security: "is_granted('ROLE_USER')",
    provider: ConversationsProvider::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Post(
    uriTemplate: '/conversations',
    openapi: new Model\Operation(
        summary: 'Create or fetch a direct conversation',
        description: 'Idempotent by participant set: when a conversation with exactly the given members plus '
            .'the caller already exists it is returned, otherwise a new one is created. Unless the caller is a '
            .'global admin, they must share at least one community with every other participant; a violation or '
            .'an empty member list fails with `422`.',
    ),
    security: "is_granted('ROLE_USER')",
    input: CreateConversationDto::class,
    processor: CreateConversationProcessor::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Get(
    uriTemplate: '/conversations/{conversation}',
    uriVariables: ['conversation' => new Link(fromClass: Conversation::class, identifiers: ['identifier'])],
    openapi: new Model\Operation(
        summary: 'Get a conversation',
        description: 'Returns the conversation with its members and a caller-scoped `unreadCount`. Participants '
            .'only — global admins have no bypass into direct messages.',
    ),
    security: "is_granted('CONVERSATION_VIEW', object)",
    provider: ConversationProvider::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Post(
    uriTemplate: '/conversations/{conversation}/mute',
    uriVariables: ['conversation' => new Link(fromClass: Conversation::class, identifiers: ['identifier'])],
    openapi: new Model\Operation(
        summary: 'Mute or unmute a conversation',
        description: 'Sets `mutedUntil` on the membership of the caller, suppressing DM notifications until '
            .'that time; `null` clears the mute. Participants only.',
    ),
    security: "is_granted('CONVERSATION_VIEW', object)",
    read: true,
    input: MuteConversationDto::class,
    provider: ConversationProvider::class,
    processor: MuteConversationProcessor::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Post(
    uriTemplate: '/conversations/{conversation}/mark-read',
    uriVariables: ['conversation' => new Link(fromClass: Conversation::class, identifiers: ['identifier'])],
    openapi: new Model\Operation(
        summary: 'Mark a conversation as read',
        description: 'Sets `lastReadAt` of the caller to now, resetting the `unreadCount` of the conversation. '
            .'Participants only.',
    ),
    security: "is_granted('CONVERSATION_VIEW', object)",
    read: true,
    input: false,
    provider: ConversationProvider::class,
    processor: MarkReadConversationProcessor::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Post(
    uriTemplate: '/conversations/{conversation}/typing',
    uriVariables: ['conversation' => new Link(fromClass: Conversation::class, identifiers: ['identifier'])],
    openapi: new Model\Operation(
        summary: 'Send a typing indicator ping',
        description: 'Publishes an ephemeral "user is typing" event to the Mercure topic of the conversation; '
            .'nothing is persisted and the response is `204`. Participants only.',
    ),
    security: "is_granted('CONVERSATION_WRITE', object)",
    read: true,
    input: false,
    output: false,
    provider: ConversationProvider::class,
    processor: PublishConversationTypingProcessor::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
class Conversation
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['conversation:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    #[Groups(['conversation:read'])]
    private string $identifier;

    #[ORM\Column(length: 40)]
    private string $participantsHash = '';

    #[ORM\Column(nullable: true)]
    #[Groups(['conversation:read'])]
    private ?\DateTimeImmutable $lastMessageAt = null;

    /** Transient, caller-scoped — hydrated by ConversationService before serialization, reads 0 otherwise. */
    #[Groups(['conversation:read'])]
    private int $unreadCount = 0;

    /**
     * @var Collection<int, ConversationMember>
     */
    #[ORM\OneToMany(targetEntity: ConversationMember::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['conversation:read'])]
    private Collection $members;

    /**
     * @var Collection<int, MessagePage>
     */
    #[ORM\OneToMany(targetEntity: MessagePage::class, mappedBy: 'conversation', orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['pageNumber' => 'ASC'])]
    private Collection $pages;

    public function __construct()
    {
        $this->identifier = strtolower((new Ulid())->toBase32());
        $this->members = new ArrayCollection();
        $this->pages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getParticipantsHash(): string
    {
        return $this->participantsHash;
    }

    public function setParticipantsHash(string $participantsHash): static
    {
        $this->participantsHash = $participantsHash;

        return $this;
    }

    /**
     * @return Collection<int, ConversationMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(ConversationMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setConversation($this);
        }

        return $this;
    }

    public function getConversationIri(): string
    {
        return '/api/conversations/'.$this->identifier;
    }

    /**
     * @return Collection<int, MessagePage>
     */
    public function getPages(): Collection
    {
        return $this->pages;
    }

    public function addPage(MessagePage $page): static
    {
        if (!$this->pages->contains($page)) {
            $this->pages->add($page);
            $page->setConversation($this);
        }

        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getUnreadCount(): int
    {
        return $this->unreadCount;
    }

    public function setUnreadCount(int $unreadCount): static
    {
        $this->unreadCount = $unreadCount;

        return $this;
    }

    public function setLastMessageAt(?\DateTimeImmutable $lastMessageAt): static
    {
        $this->lastMessageAt = $lastMessageAt;

        return $this;
    }

    /**
     * @param int[] $userIds
     */
    public static function hashParticipants(array $userIds): string
    {
        $unique = array_values(array_unique($userIds));
        sort($unique);

        return sha1(implode('-', $unique));
    }
}
