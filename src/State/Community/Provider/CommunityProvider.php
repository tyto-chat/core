<?php

declare(strict_types=1);

namespace App\State\Community\Provider;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProviderInterface<\App\Entity\Community>
 */
final readonly class CommunityProvider implements ProviderInterface
{
    public function __construct(
        private readonly CommunityServiceInterface $communityService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof Get && isset($uriVariables['identifier'])) {
            return $this->communityService->getByIdentifier($uriVariables['identifier']);
        }

        if ($operation instanceof GetCollection) {
            return $this->communityService->getAll();
        }

        return null;
    }
}
