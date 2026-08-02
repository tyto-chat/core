<?php

declare(strict_types=1);

namespace App\State\CommunityMember\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CommunityMember\UpdateCommunityMemberRoleDto;
use App\Entity\CommunityMember;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<UpdateCommunityMemberRoleDto, CommunityMember>
 */
final readonly class UpdateCommunityMemberRoleProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommunityMember
    {
        /** @var UpdateCommunityMemberRoleDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $member = $this->communityService->getMember($community, (int) $uriVariables['memberId']);

        \assert(null !== $data->role);

        return $this->communityService->updateMemberRole($member, $data->role);
    }
}
