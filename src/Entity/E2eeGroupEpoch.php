<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeGroupEpochRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: E2eeGroupEpochRepository::class)]
#[ORM\Table(name: 'e2ee_group_epoch')]
#[ORM\UniqueConstraint(name: 'UNIQ_E2EE_GROUP_EPOCH', columns: ['conversation_id', 'epoch'])]
#[ORM\UniqueConstraint(name: 'UNIQ_E2EE_GROUP_EPOCH_COMMIT', columns: ['commit_id'])]
class E2eeGroupEpoch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column]
    private int $epoch;

    #[ORM\Column]
    private int $generation;

    #[ORM\Column(length: 36)]
    private string $commitId;

    #[ORM\Column(length: 36)]
    private string $deviceId;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $commit;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $groupInfo;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $leaveRequestId;

    /** @var array<int|string, int> account generation per user id when this group generation started */
    #[ORM\Column(type: Types::JSON)]
    private array $memberGenerations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<int|string, int> $memberGenerations */
    public function __construct(
        Conversation $conversation,
        int $epoch,
        int $generation,
        string $commitId,
        string $deviceId,
        string $commit,
        string $groupInfo,
        ?string $leaveRequestId,
        array $memberGenerations,
    ) {
        $this->conversation = $conversation;
        $this->epoch = $epoch;
        $this->generation = $generation;
        $this->commitId = $commitId;
        $this->deviceId = $deviceId;
        $this->commit = $commit;
        $this->groupInfo = $groupInfo;
        $this->leaveRequestId = $leaveRequestId;
        $this->memberGenerations = $memberGenerations;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getEpoch(): int
    {
        return $this->epoch;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getCommitId(): string
    {
        return $this->commitId;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function getCommit(): string
    {
        return $this->commit;
    }

    public function getGroupInfo(): string
    {
        return $this->groupInfo;
    }

    public function getLeaveRequestId(): ?string
    {
        return $this->leaveRequestId;
    }

    /** @return array<int|string, int> */
    public function getMemberGenerations(): array
    {
        return $this->memberGenerations;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
