<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CommunityInvite;
use App\Service\Community\CommunityInviteServiceInterface;

/**
 * @implements ProcessorInterface<CommunityInvite, null>
 */
final readonly class DeleteCommunityInviteProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityInviteServiceInterface $inviteService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->inviteService->revoke($data);

        return null;
    }
}
