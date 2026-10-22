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

    /** @param string[] $topics e.g. ['c/12', 'm/3'] */
    public function create(array $topics): string
    {
        return $this->factory->create(subscribe: $topics, publish: []);
    }
}
