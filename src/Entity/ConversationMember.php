<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ConversationMemberRepository::class)]
#[ORM\Table(name: 'conversation_member')]
#[ORM\UniqueConstraint(name: 'UNIQ_CONV_MEMBER_CONV_USER', columns: ['conversation_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'IDX_CONV_MEMBER_USER')]
class ConversationMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['conversation:read', 'conversation_member:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['conversation:read', 'conversation_member:read'])]
    private User $user;

    #[ORM\Column]
    #[Groups(['conversation:read', 'conversation_member:read'])]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['conversation:read', 'conversation_member:read'])]
    private ?\DateTimeImmutable $mutedUntil = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['conversation:read', 'conversation_member:read'])]
    private ?\DateTimeImmutable $lastReadAt = null;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    #[Groups(['conversation:read', 'conversation_member:read'])]
    public function getUserId(): ?int
    {
        return $this->user->getId();
    }

    #[Groups(['conversation:read', 'conversation_member:read'])]
    public function getProfile(): ?Profile
    {
        return $this->user->getProfile();
    }

    public function getMutedUntil(): ?\DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    public function setMutedUntil(?\DateTimeImmutable $mutedUntil): static
    {
        $this->mutedUntil = $mutedUntil;

        return $this;
    }

    public function isMuted(): bool
    {
        return null !== $this->mutedUntil && $this->mutedUntil > new \DateTimeImmutable();
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(?\DateTimeImmutable $lastReadAt): static
    {
        $this->lastReadAt = $lastReadAt;

        return $this;
    }
}
