<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;
use App\Repository\ChannelUserPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelUserPreferenceRepository::class)]
#[ORM\Table(name: 'channel_user_preference')]
#[ORM\UniqueConstraint(name: 'UNIQ_CHANNEL_USER_PREF_USER_CHANNEL', columns: ['user_id', 'channel_id'])]
#[ORM\Index(columns: ['user_id'], name: 'IDX_CHANNEL_USER_PREF_USER')]
class ChannelUserPreference
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

    #[ORM\Column(length: 20, enumType: ChannelNotificationLevel::class)]
    private ChannelNotificationLevel $level = ChannelNotificationLevel::Mentions;

    #[ORM\Column(length: 16, nullable: true, enumType: ChannelPinState::class)]
    private ?ChannelPinState $pinState = null;

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

    public function getLevel(): ChannelNotificationLevel
    {
        return $this->level;
    }

    public function setLevel(ChannelNotificationLevel $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getPinState(): ?ChannelPinState
    {
        return $this->pinState;
    }

    public function setPinState(?ChannelPinState $pinState): static
    {
        $this->pinState = $pinState;

        return $this;
    }

    public function isDefault(): bool
    {
        return ChannelNotificationLevel::Mentions === $this->level && null === $this->pinState;
    }
}
