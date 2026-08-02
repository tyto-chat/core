<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use Symfony\Component\Mercure\Jwt\LcobucciFactory;

final class MercureSubscriberTokenFactory
{
    private LcobucciFactory $factory;

    public function __construct(string $subscriberJwtKey, int $tokenTtl)
    {
        $this->factory = new LcobucciFactory($subscriberJwtKey, 'hmac.sha256', $tokenTtl);
    }

    /** @param string[] $topics e.g. ['/api/communities/my-community/channels/general'] */
    public function create(array $topics): string
    {
        return $this->factory->create(subscribe: $topics, publish: []);
    }
}
