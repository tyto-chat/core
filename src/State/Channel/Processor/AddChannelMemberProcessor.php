<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Channel\AddChannelMemberDto;
use App\Entity\ChannelMember;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<AddChannelMemberDto, ChannelMember>
 */
final readonly class AddChannelMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private ChannelServiceInterface $channelService,
        private ChannelMembershipServiceInterface $channelMembershipService,
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChannelMember
    {
        /** @var AddChannelMemberDto $data */
        $community = $this->communityService->getByIdentifier((string) $uriVariables['community']);
        $channel = $this->channelService->getByIdentifier((string) $uriVariables['channel'], $community);

        $user = $this->userService->get($data->userId);

        return $this->channelMembershipService->addMember($channel, $user, $data->role);
    }
}
