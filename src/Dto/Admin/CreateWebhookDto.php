<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CreateWebhookDto
{
    /**
     * @param array<string, mixed>|null $filters
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 120)]
        #[Groups(['admin_webhook:write'])]
        public string $name = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 2048)]
        #[Groups(['admin_webhook:write'])]
        public string $url = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 64)]
        #[Groups(['admin_webhook:write'])]
        public string $triggerKey = '',

        #[Groups(['admin_webhook:write'])]
        public ?array $filters = null,
    ) {
    }
}
