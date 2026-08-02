<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserGroup;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProcessorInterface<UserGroup, null>
 */
final readonly class RemoveGroupChannelPermissionProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var UserGroup $data */
        $channelIdentifier = $uriVariables['channelIdentifier'];

        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier((string) $channelIdentifier, $community);
        $this->userGroupService->removeChannelPermission($data, $channel);

        return null;
    }
}
