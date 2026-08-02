<?php

declare(strict_types=1);

namespace App\Service\IpReputation;

use App\Enum\IpReputation\IpReputationVerdict;

interface IpReputationServiceInterface
{
    public function check(string $ip, string $email, ?string $username = null): IpReputationVerdict;
}
