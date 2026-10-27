<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\BlockedUserDto;
use App\Dto\User\BlockUserDto;
use App\Service\User\UserBlockServiceInterface;

/**
 * @implements ProcessorInterface<BlockUserDto, BlockedUserDto>
 */
final readonly class BlockUserProcessor implements ProcessorInterface
{
    public function __construct(
        private UserBlockServiceInterface $blocks,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BlockedUserDto
    {
        \assert(null !== $data->userId);

        return BlockedUserDto::fromBlock($this->blocks->block($data->userId), true);
    }
}
