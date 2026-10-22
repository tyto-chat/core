<?php

declare(strict_types=1);

namespace App\State\Realtime\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Realtime\RealtimeTokenDto;
use App\Service\Realtime\MercureSubscriberTokenFactory;
use App\Service\Realtime\RealtimeTopicResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<RealtimeTokenDto>
 */
final readonly class PublicRealtimeTokenProvider implements ProviderInterface
{
    public function __construct(
        private MercureSubscriberTokenFactory $tokenFactory,
        private RealtimeTopicResolver $topicResolver,
        private RequestStack $requestStack,
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private int $tokenTtl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RealtimeTokenDto
    {
        $dto = new RealtimeTokenDto();

        $query = $this->requestStack->getCurrentRequest()?->query;
        $topics = $this->topicResolver->forAnonymous($query?->getString('community'));
        if ([] === $topics) {
            return $dto;
        }

        $dto->token = $this->tokenFactory->create($topics);
        $dto->expiresAt = time() + $this->tokenTtl;

        return $dto;
    }
}
