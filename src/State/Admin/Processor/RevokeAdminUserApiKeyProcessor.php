<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Enum\Admin\AdminAuditAction;
use App\Repository\ApiKeyRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\ApiKey\ApiKeyServiceInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class RevokeAdminUserApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private ApiKeyRepository $apiKeyRepository,
        private ApiKeyServiceInterface $apiKeyService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = (int) ($uriVariables['id'] ?? 0);
        $keyId = (int) ($uriVariables['keyId'] ?? 0);

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException('User not found.');
        }

        $key = $this->apiKeyRepository->find($keyId);
        if (!$key instanceof ApiKey || $key->getUser() !== $user) {
            throw new NotFoundHttpException('API key not found.');
        }

        $this->apiKeyService->revoke($key);
        $this->auditLogger->record(
            AdminAuditAction::UserRevokeApiKey,
            'user',
            $id,
            ['apiKeyId' => $keyId, 'apiKeyName' => $key->getName()],
        );

        return null;
    }
}
