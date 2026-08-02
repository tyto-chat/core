<?php

declare(strict_types=1);

namespace App\State\ApiKey\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ApiKey\IssueApiKeyDto;
use App\Dto\ApiKey\IssuedApiKeyDto;
use App\Service\ApiKey\ApiKeyServiceInterface;

/**
 * @implements ProcessorInterface<IssueApiKeyDto, IssuedApiKeyDto>
 */
final readonly class IssueApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiKeyServiceInterface $apiKeyService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssuedApiKeyDto
    {
        /** @var IssueApiKeyDto $data */
        $issued = $this->apiKeyService->issue($data->name, $data->scopes, $data->expiresAt);

        return IssuedApiKeyDto::fromEntity($issued->key, $issued->plainToken);
    }
}
