<?php

declare(strict_types=1);

namespace App\State\CommunityMember\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CommunityMember\AddCommunityMemberDto;
use App\Entity\CommunityMember;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<AddCommunityMemberDto, CommunityMember>
 */
final readonly class AddCommunityMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommunityMember
    {
        /** @var AddCommunityMemberDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $user = $this->userService->get($data->userId);

        return $this->communityService->addMember($community, $user, $data->role);
    }
}
