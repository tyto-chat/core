<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PresenceSampleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PresenceSampleRepository::class)]
#[ORM\Index(name: 'idx_presence_sample_community_time', columns: ['community_id', 'sampled_at'])]
class PresenceSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Community $community;

    #[ORM\Column]
    private int $membersOnline;

    #[ORM\Column]
    private int $guestsOnline;

    #[ORM\Column]
    private \DateTimeImmutable $sampledAt;

    public function __construct(Community $community, int $membersOnline, int $guestsOnline, \DateTimeImmutable $sampledAt)
    {
        $this->community = $community;
        $this->membersOnline = $membersOnline;
        $this->guestsOnline = $guestsOnline;
        $this->sampledAt = $sampledAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommunity(): Community
    {
        return $this->community;
    }

    public function getMembersOnline(): int
    {
        return $this->membersOnline;
    }

    public function getGuestsOnline(): int
    {
        return $this->guestsOnline;
    }

    public function getSampledAt(): \DateTimeImmutable
    {
        return $this->sampledAt;
    }
}
