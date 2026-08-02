<?php

declare(strict_types=1);

namespace App\Dto\UserGroup;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateUserGroupDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        #[Groups(['user_group:write'])]
        public string $name,
        #[Assert\Length(max: 10)]
        #[Groups(['user_group:write'])]
        public ?string $icon = null,
        #[Assert\AtLeastOneOf([
            new Assert\IsNull(),
            new Assert\Regex(
                pattern: '/^#[0-9a-f]{6}$/',
                message: 'color must be null or a lowercase hex color in #rrggbb format.',
            ),
        ])]
        #[Groups(['user_group:write'])]
        public ?string $color = null,
        #[Groups(['user_group:write'])]
        public bool $isHidden = false,
    ) {
    }
}
