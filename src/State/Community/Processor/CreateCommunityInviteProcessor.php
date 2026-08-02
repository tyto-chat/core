<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Community\CreateCommunityInviteDto;
use App\Entity\CommunityInvite;
use App\Service\Community\CommunityInviteServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<CreateCommunityInviteDto, CommunityInvite>
 */
final readonly class CreateCommunityInviteProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityInviteServiceInterface $inviteService,
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommunityInvite
    {
        /** @var CreateCommunityInviteDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->inviteService->create($community, $data->maxUses, $data->expiresAt);
    }
}
