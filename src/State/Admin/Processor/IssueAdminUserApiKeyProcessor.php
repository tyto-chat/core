<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ApiKey\IssueApiKeyDto;
use App\Dto\ApiKey\IssuedApiKeyDto;
use App\Entity\User;
use App\Enum\Admin\AdminAuditAction;
use App\Exception\ApiKey\ApiKeyTargetNotBotException;
use App\Repository\UserRepository;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\ApiKey\ApiKeyServiceInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<IssueApiKeyDto, IssuedApiKeyDto>
 */
final readonly class IssueAdminUserApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private ApiKeyServiceInterface $apiKeyService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssuedApiKeyDto
    {
        /** @var IssueApiKeyDto $data */
        $id = (int) ($uriVariables['id'] ?? 0);

        $target = $this->userRepository->find($id);
        if (!$target instanceof User) {
            throw new NotFoundHttpException('User not found.');
        }
        if (!$target->isBot()) {
            throw new ApiKeyTargetNotBotException('API keys can only be issued for bot accounts.');
        }

        $issued = $this->apiKeyService->issueFor($target, $data->name, $data->scopes, $data->expiresAt);

        $this->auditLogger->record(
            AdminAuditAction::UserIssueApiKey,
            'user',
            $id,
            ['apiKeyId' => $issued->key->getId(), 'apiKeyName' => $issued->key->getName(), 'scopes' => $data->scopes],
        );

        return IssuedApiKeyDto::fromEntity($issued->key, $issued->plainToken);
    }
}
