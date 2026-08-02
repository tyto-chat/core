<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Channel\UpdateChannelMemberRoleDto;
use App\Entity\ChannelMember;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<UpdateChannelMemberRoleDto, ChannelMember>
 */
final readonly class UpdateChannelMemberRoleProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private ChannelMembershipServiceInterface $channelMembershipService,
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChannelMember
    {
        /** @var UpdateChannelMemberRoleDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier($uriVariables['channel'], $community);
        $userId = $uriVariables['userId'];
        $user = $this->userService->get((int) $userId);
        $channelMember = $this->channelMembershipService->getMember($channel, $user);

        \assert(null !== $data->role);

        return $this->channelMembershipService->updateMemberRole($channelMember, $data->role);
    }
}
