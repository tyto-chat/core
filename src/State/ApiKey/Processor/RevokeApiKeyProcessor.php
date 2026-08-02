<?php

declare(strict_types=1);

namespace App\State\ApiKey\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ApiKey;
use App\Service\ApiKey\ApiKeyServiceInterface;

/**
 * @implements ProcessorInterface<ApiKey, null>
 */
final readonly class RevokeApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiKeyServiceInterface $apiKeyService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->apiKeyService->revoke($data);

        return null;
    }
}
