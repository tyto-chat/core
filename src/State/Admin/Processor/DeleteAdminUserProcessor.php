<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Admin\AdminUserServiceInterface;
use App\Utils\JsonRequestBody;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteAdminUserProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminUserServiceInterface $adminUserService,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $body = JsonRequestBody::decode($this->requestStack->getCurrentRequest());
        $confirm = $body['confirm'] ?? null;

        $this->adminUserService->forceDelete(
            (int) ($uriVariables['id'] ?? 0),
            is_string($confirm) ? $confirm : null,
        );

        return null;
    }
}
