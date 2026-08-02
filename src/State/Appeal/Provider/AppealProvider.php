<?php

declare(strict_types=1);

namespace App\State\Appeal\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Appeal;
use App\Service\Moderation\AppealServiceInterface;

/**
 * @implements ProviderInterface<Appeal>
 */
final readonly class AppealProvider implements ProviderInterface
{
    public function __construct(
        private AppealServiceInterface $appealService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Appeal
    {
        return $this->appealService->get((int) $uriVariables['id']);
    }
}
