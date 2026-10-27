<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\User\BlockStatusDto;
use App\Service\User\UserBlockServiceInterface;

/**
 * @implements ProviderInterface<BlockStatusDto>
 */
final readonly class BlockStatusProvider implements ProviderInterface
{
    public function __construct(private UserBlockServiceInterface $blocks)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BlockStatusDto
    {
        $userId = (int) $uriVariables['userId'];
        $status = $this->blocks->statusForCurrentUser($userId);

        return new BlockStatusDto($userId, $status['blocked'], $status['blockable'], $status['inEffect']);
    }
}
