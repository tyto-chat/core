<?php

declare(strict_types=1);

namespace App\Dto\PushSubscription;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class PushSubscriptionKeysDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Groups(['push_subscription:write'])]
        public string $p256dh,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Groups(['push_subscription:write'])]
        public string $auth,
    ) {
    }
}
