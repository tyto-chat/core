<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Admin\AdminAuditAction;
use App\Repository\AdminAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Index(name: 'admin_audit_actor_idx', columns: ['actor_id'])]
#[ORM\Index(name: 'admin_audit_action_idx', columns: ['action'])]
#[ORM\Index(name: 'admin_audit_target_idx', columns: ['target_type', 'target_id'])]
class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(length: 64, enumType: AdminAuditAction::class)]
    private AdminAuditAction $action;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $targetType;

    #[ORM\Column(nullable: true)]
    private ?int $targetId;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        ?User $actor,
        AdminAuditAction $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $payload = null,
    ) {
        $this->actor = $actor;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getAction(): AdminAuditAction
    {
        return $this->action;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
