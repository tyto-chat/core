<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserGroup\SetGroupChannelPermissionDto;
use App\Entity\GroupChannelPermission;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProcessorInterface<SetGroupChannelPermissionDto, GroupChannelPermission>
 */
final readonly class SetGroupChannelPermissionProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupChannelPermission
    {
        \assert($data instanceof SetGroupChannelPermissionDto);

        $community = $this->communityService->getByIdentifier((string) $uriVariables['community']);
        $group = $this->userGroupService->getByIdentifier((string) $uriVariables['identifier'], $community);
        $channel = $this->channelService->getByIdentifier((string) $uriVariables['channelIdentifier'], $community);

        \assert(null !== $data->role);

        return $this->userGroupService->setChannelPermission($group, $channel, $data->role);
    }
}
