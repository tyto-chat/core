<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserBlocks;
use App\Dto\User\BlockedUserDto;
use App\Service\User\UserBlockServiceInterface;

/**
 * @implements ProviderInterface<UserBlocks>
 */
final readonly class BlockedUsersProvider implements ProviderInterface
{
    public function __construct(
        private UserBlockServiceInterface $blocks,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserBlocks
    {
        $resource = new UserBlocks();
        foreach ($this->blocks->listForCurrentUser() as $row) {
            $resource->items[] = BlockedUserDto::fromBlock($row['block'], $row['inEffect']);
        }

        return $resource;
    }
}
