<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Entity\Community;
use App\Service\Presence\GuestPresenceServiceInterface;

final class InMemoryGuestPresenceService implements GuestPresenceServiceInterface
{
    /** @var array<int, array<string, true>> */
    private array $visitors = [];

    public function touch(Community $community, string $visitorKey): void
    {
        $this->visitors[$community->getId()][$visitorKey] = true;
    }

    public function getGuestCount(Community $community): int
    {
        return \count($this->visitors[$community->getId()] ?? []);
    }

    public function reset(): void
    {
        $this->visitors = [];
    }
}
