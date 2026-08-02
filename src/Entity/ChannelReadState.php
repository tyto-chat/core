<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChannelReadStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelReadStateRepository::class)]
#[ORM\Table(name: 'channel_read_state')]
#[ORM\UniqueConstraint(name: 'UNIQ_CHANNEL_READ_STATE_USER_CHANNEL', columns: ['user_id', 'channel_id'])]
#[ORM\Index(columns: ['user_id'], name: 'IDX_CHANNEL_READ_STATE_USER')]
class ChannelReadState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    #[ORM\Column]
    private \DateTimeImmutable $lastReadAt;

    public function __construct()
    {
        $this->lastReadAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function setChannel(Channel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getLastReadAt(): \DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(\DateTimeImmutable $lastReadAt): static
    {
        $this->lastReadAt = $lastReadAt;

        return $this;
    }
}
