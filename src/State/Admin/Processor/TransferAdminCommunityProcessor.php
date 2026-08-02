<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminCommunityTransferDto;
use App\Dto\Admin\AdminCommunityTransferResultDto;
use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<AdminCommunityTransferDto, AdminCommunityTransferResultDto>
 */
final readonly class TransferAdminCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserServiceInterface $userService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminCommunityTransferResultDto
    {
        \assert($data instanceof AdminCommunityTransferDto);

        $identifier = $uriVariables['identifier'] ?? null;
        if (!is_string($identifier)) {
            throw new BadRequestHttpException('Missing community identifier.');
        }
        if (null === $data->newAdminUserId) {
            throw new BadRequestHttpException('Field "newAdminUserId" is required.');
        }

        $community = $this->communityService->getByIdentifier($identifier);
        $newAdmin = $this->userService->get($data->newAdminUserId);

        $demoted = $this->communityService->transferAdminRole($community, $newAdmin, $data->demoteOthers);

        $this->auditLogger->record(
            AdminAuditAction::CommunityTransfer,
            'community',
            null,
            [
                'communityIdentifier' => $community->getIdentifier(),
                'newAdminUserId' => $newAdmin->getId(),
                'demoteOthers' => $data->demoteOthers,
                'demotedCount' => $demoted,
            ],
        );

        return new AdminCommunityTransferResultDto(true, $demoted);
    }
}
