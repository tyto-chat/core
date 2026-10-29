<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeLeaveRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: E2eeLeaveRequestRepository::class)]
#[ORM\Table(name: 'e2ee_leave_request')]
#[ORM\UniqueConstraint(name: 'UNIQ_E2EE_LEAVE_REQUEST_CONVERSATION', columns: ['leave_request_id', 'conversation_id'])]
#[ORM\Index(columns: ['conversation_id', 'fulfilled_at'], name: 'IDX_E2EE_LEAVE_REQUEST_PENDING')]
class E2eeLeaveRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $leaveRequestId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeDevice $device;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    public function __construct(string $leaveRequestId, E2eeDevice $device, Conversation $conversation)
    {
        $this->leaveRequestId = $leaveRequestId;
        $this->device = $device;
        $this->conversation = $conversation;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLeaveRequestId(): string
    {
        return $this->leaveRequestId;
    }

    public function getDevice(): E2eeDevice
    {
        return $this->device;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isFulfilled(): bool
    {
        return null !== $this->fulfilledAt;
    }

    public function fulfil(): void
    {
        $this->fulfilledAt ??= new \DateTimeImmutable();
    }
}
