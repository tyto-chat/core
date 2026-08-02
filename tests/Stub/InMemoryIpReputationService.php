<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Enum\IpReputation\IpReputationVerdict;
use App\Service\IpReputation\IpReputationServiceInterface;

final class InMemoryIpReputationService implements IpReputationServiceInterface
{
    public IpReputationVerdict $verdict = IpReputationVerdict::Clean;

    /** @var array{0: string, 1: string, 2: ?string}|null */
    public ?array $lastCheck = null;

    #[\Override]
    public function check(string $ip, string $email, ?string $username = null): IpReputationVerdict
    {
        $this->lastCheck = [$ip, $email, $username];

        return $this->verdict;
    }
}
