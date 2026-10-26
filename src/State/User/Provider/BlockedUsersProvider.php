<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserBlocks;
use App\Dto\User\BlockedUserDto;
use App\Service\MediaObject\AvatarUrlResolverInterface;
use App\Service\User\UserBlockServiceInterface;

/**
 * @implements ProviderInterface<UserBlocks>
 */
final readonly class BlockedUsersProvider implements ProviderInterface
{
    public function __construct(
        private UserBlockServiceInterface $blocks,
        private AvatarUrlResolverInterface $avatarUrls,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserBlocks
    {
        $resource = new UserBlocks();
        foreach ($this->blocks->listForCurrentUser() as $row) {
            $avatar = $row['block']->getBlocked()->getProfile()?->getAvatar();
            $resource->items[] = BlockedUserDto::fromBlock($row['block'], $row['inEffect'], $this->avatarUrls->avatarUrl($avatar));
        }

        return $resource;
    }
}
