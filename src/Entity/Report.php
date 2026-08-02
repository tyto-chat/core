<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Report\CreateReportDto;
use App\Dto\Report\UpdateReportDto;
use App\Enum\Moderation\ReportCategory;
use App\Enum\Moderation\ReportStatus;
use App\Repository\ReportRepository;
use App\State\Report\Processor\CreateReportProcessor;
use App\State\Report\Processor\UpdateReportProcessor;
use App\State\Report\Provider\AdminReportsProvider;
use App\State\Report\Provider\CommunityReportsProvider;
use App\State\Report\Provider\ReportProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Index(name: 'idx_report_community_status', columns: ['community_id', 'status'])]
#[ORM\Index(name: 'idx_report_status', columns: ['status'])]
#[ApiResource(
    description: 'A user-filed report flagging a message or a user for moderator review.',
    normalizationContext: ['groups' => ['report:read', 'user:embed']],
)]
#[Post(
    uriTemplate: '/reports',
    security: "is_granted('ROLE_USER')",
    input: CreateReportDto::class,
    processor: CreateReportProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Report a message or user',
        description: 'Any signed-in user files a report against either a message (`messageId`) or a user '
            .'(`userId`, optionally scoped to a community via `communityIdentifier`). Message reports snapshot '
            .'the message text and must target a message the caller can view. Notifies the community\'s '
            .'moderators, or server admins when the report has no community context. Rejects self-reports, '
            .'system messages, and duplicate pending reports for the same target with `422`.',
    ),
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/reports',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: CommunityReportsProvider::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'List a community\'s reports',
        description: 'Lists reports filed in this community, newest first. Callable by the community\'s '
            .'moderators and admins, or a global admin. Supports `status` and `page` query filters.',
    ),
)]
#[GetCollection(
    uriTemplate: '/admin/reports',
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminReportsProvider::class,
    extraProperties: ['scope' => 'admin'],
    openapi: new Model\Operation(
        summary: 'List all reports server-wide',
        description: 'Global admins only. Lists reports across all communities, including reports without a '
            .'community context (e.g. DM message reports). Supports `status` and `page` query filters.',
    ),
)]
#[Patch(
    uriTemplate: '/reports/{id}',
    security: "is_granted('ROLE_USER')",
    input: UpdateReportDto::class,
    provider: ReportProvider::class,
    processor: UpdateReportProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Update a report\'s status',
        description: 'Moderators and admins of the report\'s community may resolve, dismiss, or escalate it; '
            .'reports without a community, and any report already `escalated`, can only be closed by a global '
            .'admin. Escalating requires status `open` and notifies server admins; closing stamps the resolver '
            .'and note and notifies the reporter of the outcome. Already-closed reports return `422`.',
    ),
)]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['report:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['report:read'])]
    private ?User $reporter = null;

    #[ORM\ManyToOne(targetEntity: Community::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Community $community = null;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Message $message = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['report:read'])]
    private User $reportedUser;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['report:read'])]
    private ?string $messageTextSnapshot = null;

    #[ORM\Column(length: 20, enumType: ReportCategory::class)]
    #[Groups(['report:read'])]
    private ReportCategory $category;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['report:read'])]
    private ?string $comment = null;

    #[ORM\Column(length: 20, enumType: ReportStatus::class)]
    #[Groups(['report:read'])]
    private ReportStatus $status = ReportStatus::Open;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['report:read'])]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['report:read'])]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['report:read'])]
    private ?string $resolutionNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['report:read'])]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Groups(['report:read'])]
    public function getCommunityIdentifier(): ?string
    {
        return $this->community?->getIdentifier();
    }

    #[Groups(['report:read'])]
    public function getCommunityName(): ?string
    {
        return $this->community?->getName();
    }

    #[Groups(['report:read'])]
    public function getMessageId(): ?string
    {
        return $this->message?->getId();
    }

    #[Groups(['report:read'])]
    public function isMessageDeleted(): ?bool
    {
        return $this->message?->isDeleted();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(?User $reporter): static
    {
        $this->reporter = $reporter;

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

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getReportedUser(): User
    {
        return $this->reportedUser;
    }

    public function setReportedUser(User $reportedUser): static
    {
        $this->reportedUser = $reportedUser;

        return $this;
    }

    public function getMessageTextSnapshot(): ?string
    {
        return $this->messageTextSnapshot;
    }

    public function setMessageTextSnapshot(?string $messageTextSnapshot): static
    {
        $this->messageTextSnapshot = $messageTextSnapshot;

        return $this;
    }

    public function getCategory(): ReportCategory
    {
        return $this->category;
    }

    public function setCategory(ReportCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function setStatus(ReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function setResolvedBy(?User $resolvedBy): static
    {
        $this->resolvedBy = $resolvedBy;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeInterface
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeInterface $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getResolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function setResolutionNote(?string $resolutionNote): static
    {
        $this->resolutionNote = $resolutionNote;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
