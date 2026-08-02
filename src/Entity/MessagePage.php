<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\OpenApi\Model;
use App\Repository\MessagePageRepository;
use App\State\MessagePage\Provider\ChannelMessagePageProvider;
use App\State\MessagePage\Provider\ChannelMessagePagesProvider;
use App\State\MessagePage\Provider\ConversationMessagePageProvider;
use App\State\MessagePage\Provider\ConversationMessagePagesProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Entity(repositoryClass: MessagePageRepository::class)]
#[ORM\Table(name: 'message_page')]
#[ORM\UniqueConstraint(name: 'uniq_message_page_channel_number', columns: ['channel_id', 'page_number'])]
#[ORM\UniqueConstraint(name: 'uniq_message_page_conversation_number', columns: ['conversation_id', 'page_number'])]
#[ApiResource(
    description: 'A fixed-size page grouping up to 50 root messages of a channel or a direct conversation.',
    normalizationContext: ['groups' => ['message_page:read']],
    paginationItemsPerPage: 20,
    paginationMaximumItemsPerPage: 30,
)]
#[NotExposed]
#[GetCollection(
    uriTemplate: '/communities/{community}/channels/{channel}/pages',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    openapi: new Model\Operation(
        summary: 'List message pages of a channel',
        description: 'Returns page metadata (page numbers and message counts) without message bodies. '
            .'Available to anyone who may view the channel, including anonymous callers on public channels '
            .'of public communities.',
    ),
    provider: ChannelMessagePagesProvider::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[Get(
    uriTemplate: '/communities/{community}/channels/{channel}/pages/{pageNumber}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
        'pageNumber' => new Link(fromClass: MessagePage::class, identifiers: ['pageNumber']),
    ],
    openapi: new Model\Operation(
        summary: 'Get a numbered message page of a channel',
        description: 'Returns the page with hydrated root messages; thread replies are excluded. Audience '
            .'matches viewing the channel (anonymous access works on public channels of public communities); '
            .'unknown page numbers yield `404`.',
    ),
    normalizationContext: ['groups' => ['message_page:read', 'message_page:detail', 'message:read']],
    provider: ChannelMessagePageProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'messages'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
)]
#[GetCollection(
    uriTemplate: '/conversations/{conversation}/pages',
    uriVariables: [
        'conversation' => new Link(fromClass: Conversation::class, identifiers: ['identifier']),
    ],
    openapi: new Model\Operation(
        summary: 'List message pages of a conversation',
        description: 'Returns page metadata (page numbers and message counts) without message bodies. '
            .'Participants only — there is no admin bypass for direct messages.',
    ),
    provider: ConversationMessagePagesProvider::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
#[Get(
    uriTemplate: '/conversations/{conversation}/pages/{pageNumber}',
    uriVariables: [
        'conversation' => new Link(fromClass: Conversation::class, identifiers: ['identifier']),
        'pageNumber' => new Link(fromClass: MessagePage::class, identifiers: ['pageNumber']),
    ],
    openapi: new Model\Operation(
        summary: 'Get a numbered message page of a conversation',
        description: 'Returns the page with hydrated root messages; thread replies are excluded. Participants '
            .'only — there is no admin bypass for direct messages; unknown page numbers yield `404`.',
    ),
    normalizationContext: ['groups' => ['message_page:read', 'message_page:detail', 'message:read']],
    provider: ConversationMessagePageProvider::class,
    extraProperties: ['scopeResource' => 'conversations'],
)]
class MessagePage
{
    use TimestampableEntity;

    public const int PAGE_SIZE = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['message_page:read'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['message_page:read'])]
    private int $pageNumber = 0;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'page')]
    private Collection $messages;

    /**
     * @var Message[]|null
     */
    private ?array $hydratedMessages = null;

    #[ORM\ManyToOne(inversedBy: 'pages')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Channel $channel = null;

    #[ORM\ManyToOne(inversedBy: 'pages')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Conversation $conversation = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function setPageNumber(int $pageNumber): static
    {
        $this->pageNumber = $pageNumber;

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setPage($this);
        }

        return $this;
    }

    /**
     * Must NOT fall back to the raw ORM collection — that would leak thread replies and unhydrated/deleted text.
     *
     * @return Message[]
     */
    #[Groups(['message_page:detail'])]
    #[SerializedName('messages')]
    public function getHydratedMessages(): array
    {
        return $this->hydratedMessages ?? [];
    }

    /**
     * @param Message[] $messages
     */
    public function setHydratedMessages(array $messages): static
    {
        $this->hydratedMessages = $messages;

        return $this;
    }

    public function getChannel(): ?Channel
    {
        return $this->channel;
    }

    public function setChannel(?Channel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    #[Groups(['message_page:read'])]
    public function getMessageCount(): int
    {
        return null !== $this->hydratedMessages ? \count($this->hydratedMessages) : $this->messages->count();
    }

    public function getCommunity(): ?Community
    {
        return $this->channel?->getCommunity();
    }
}
