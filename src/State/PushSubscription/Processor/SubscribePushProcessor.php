<?php

declare(strict_types=1);

namespace App\State\PushSubscription\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PushSubscription\PushSubscriptionInputDto;
use App\Service\Notification\PushSubscriptionServiceInterface;

/**
 * @implements ProcessorInterface<PushSubscriptionInputDto, null>
 */
final readonly class SubscribePushProcessor implements ProcessorInterface
{
    public function __construct(
        private PushSubscriptionServiceInterface $service,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        \assert(null !== $data->keys);

        $this->service->subscribe($data->endpoint, $data->keys->p256dh, $data->keys->auth, $data->locale);

        return null;
    }
}
