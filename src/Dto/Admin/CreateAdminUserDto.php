<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CreateAdminUserDto
{
    #[Groups(['admin_user:write'])]
    public bool $isBot = false;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['admin_user:write'])]
    public string $name = '';

    #[Assert\Email]
    #[Groups(['admin_user:write'])]
    public ?string $email = null;

    /** @var int[] */
    #[Assert\All([new Assert\Type('integer')])]
    #[Groups(['admin_user:write'])]
    public array $communityIds = [];
}
