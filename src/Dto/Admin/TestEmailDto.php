<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TestEmailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(min: 3, max: 320)]
        #[Groups(['admin_server_config:write'])]
        public string $to = '',
    ) {
    }
}
