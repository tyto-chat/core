<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Community\CommunityServiceInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteAdminCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $identifier = $uriVariables['identifier'] ?? null;
        if (!is_string($identifier)) {
            throw new BadRequestHttpException('Missing community identifier.');
        }

        $community = $this->communityService->getByIdentifier($identifier);
        $payload = [
            'identifier' => $community->getIdentifier(),
            'name' => $community->getName(),
        ];

        $this->communityService->delete($community);

        $this->auditLogger->record(AdminAuditAction::CommunityDelete, 'community', null, $payload);

        return null;
    }
}
