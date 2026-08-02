<?php

declare(strict_types=1);

namespace App\State\PushSubscription\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Notification\PushSubscriptionServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class UnsubscribePushProcessor implements ProcessorInterface
{
    public function __construct(
        private PushSubscriptionServiceInterface $service,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $endpoint = $this->requestStack->getCurrentRequest()?->query->get('endpoint');
        if (!is_string($endpoint) || '' === $endpoint) {
            throw new BadRequestHttpException('Query must include endpoint.');
        }

        $this->service->unsubscribe($endpoint);

        return null;
    }
}
