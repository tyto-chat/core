<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Service\E2ee\E2eeServiceInterface;
use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CommitInputDto
{
    public const int MAX_BLOB_BYTES = 131072;

    #[Assert\NotBlank]
    #[Assert\Uuid(versions: [Assert\Uuid::V4_RANDOM], strict: true)]
    #[Assert\Regex(pattern: '/^[0-9a-f-]+$/')]
    #[Groups(['e2ee:write'])]
    public ?string $commitId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid(versions: [Assert\Uuid::V4_RANDOM], strict: true)]
    #[Assert\Regex(pattern: '/^[0-9a-f-]+$/')]
    #[Groups(['e2ee:write'])]
    public ?string $deviceId = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['e2ee:write'])]
    public ?int $epoch = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['e2ee:write'])]
    public ?int $generation = null;

    #[Assert\NotBlank]
    #[Base64Bytes(max: self::MAX_BLOB_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $commit = null;

    #[Assert\NotBlank]
    #[Base64Bytes(max: self::MAX_BLOB_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $groupInfo = null;

    /** @var list<WelcomeInputDto> */
    #[Assert\Count(max: 2 * E2eeServiceInterface::MAX_ACTIVE_DEVICES)]
    #[Assert\Valid]
    #[Groups(['e2ee:write'])]
    public array $welcomes = [];

    #[Assert\Uuid(versions: [Assert\Uuid::V4_RANDOM], strict: true)]
    #[Assert\Regex(pattern: '/^[0-9a-f-]+$/')]
    #[Groups(['e2ee:write'])]
    public ?string $leaveRequestId = null;
}
