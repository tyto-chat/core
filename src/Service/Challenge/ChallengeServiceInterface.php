<?php

declare(strict_types=1);

namespace App\Service\Challenge;

use App\Dto\Challenge\CreateChallengeDto;
use App\Entity\Challenge;
use App\Exception\Challenge\InvalidChallengeException;

interface ChallengeServiceInterface
{
    public function new(CreateChallengeDto $createChallengeDto, ?int $expiryInMinutes = null): Challenge;

    /**
     * @throws InvalidChallengeException
     */
    public function get(string $email, string $token): Challenge;

    public function consume(Challenge $challenge): void;

    public function isValid(string $email, string $token): bool;
}
