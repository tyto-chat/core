<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminApiKeyRowDto;
use App\Dto\Admin\AdminUserApiKeysDto;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<AdminUserApiKeysDto>
 */
final readonly class AdminUserApiKeysProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private ApiKeyRepository $apiKeyRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminUserApiKeysDto
    {
        $user = $this->userRepository->find((int) ($uriVariables['id'] ?? 0));
        if (!$user instanceof User) {
            throw new NotFoundHttpException('User not found.');
        }

        $dto = new AdminUserApiKeysDto();
        foreach ($this->apiKeyRepository->findByUser($user) as $key) {
            $dto->rows[] = AdminApiKeyRowDto::fromApiKey($key);
        }

        return $dto;
    }
}
