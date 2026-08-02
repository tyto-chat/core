<?php

declare(strict_types=1);

namespace App\State\ApiKey\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ApiKey;
use App\Service\ApiKey\ApiKeyServiceInterface;

/**
 * @implements ProviderInterface<ApiKey>
 */
final readonly class ApiKeysProvider implements ProviderInterface
{
    public function __construct(
        private ApiKeyServiceInterface $apiKeyService,
    ) {
    }

    /**
     * @return iterable<ApiKey>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        return $this->apiKeyService->listForCurrentUser();
    }
}
