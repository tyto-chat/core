<?php

declare(strict_types=1);

namespace App\Dto\PushSubscription;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class PushSubscriptionInputDto
{
    public function __construct(
        // Defaults let the serializer instantiate on absent fields — missing values 422 via validation instead of 500.
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        #[Assert\Url(protocols: ['https'], requireTld: true)]
        #[Groups(['push_subscription:write'])]
        public string $endpoint = '',
        #[Assert\NotNull]
        #[Assert\Valid]
        #[Groups(['push_subscription:write'])]
        public ?PushSubscriptionKeysDto $keys = null,
        #[Assert\Length(max: 8)]
        #[Groups(['push_subscription:write'])]
        public string $locale = 'en',
    ) {
    }
}
