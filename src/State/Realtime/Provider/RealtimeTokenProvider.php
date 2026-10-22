<?php

declare(strict_types=1);

namespace App\State\Realtime\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Realtime\RealtimeTokenDto;
use App\Entity\User;
use App\Service\Realtime\MercureSubscriberTokenFactory;
use App\Service\Realtime\RealtimeTopicResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<RealtimeTokenDto>
 */
final readonly class RealtimeTokenProvider implements ProviderInterface
{
    public function __construct(
        private MercureSubscriberTokenFactory $tokenFactory,
        private RealtimeTopicResolver $topicResolver,
        private RequestStack $requestStack,
        private Security $security,
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private int $tokenTtl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RealtimeTokenDto
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new RealtimeTokenDto();
        }

        $query = $this->requestStack->getCurrentRequest()?->query;
        $topics = $this->topicResolver->forUser(
            $user,
            $query?->getString('community'),
            $query?->getString('conversation'),
        );

        $dto = new RealtimeTokenDto();
        $dto->token = $this->tokenFactory->create($topics);
        $dto->expiresAt = time() + $this->tokenTtl;

        return $dto;
    }
}
