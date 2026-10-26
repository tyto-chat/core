<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\BlockedUserDto;
use App\Dto\User\BlockUserDto;
use App\Service\MediaObject\AvatarUrlResolverInterface;
use App\Service\User\UserBlockServiceInterface;

/**
 * @implements ProcessorInterface<BlockUserDto, BlockedUserDto>
 */
final readonly class BlockUserProcessor implements ProcessorInterface
{
    public function __construct(
        private UserBlockServiceInterface $blocks,
        private AvatarUrlResolverInterface $avatarUrls,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BlockedUserDto
    {
        \assert(null !== $data->userId);
        $block = $this->blocks->block($data->userId);
        $avatar = $block->getBlocked()->getProfile()?->getAvatar();

        return BlockedUserDto::fromBlock($block, true, $this->avatarUrls->avatarUrl($avatar));
    }
}
