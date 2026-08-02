<?php

declare(strict_types=1);

namespace App\State\Moderation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ModerationAction;
use App\Enum\Moderation\ModerationActionType;
use App\Security\ApiKeyScopeGuard;
use App\Service\Moderation\ModerationServiceInterface;

/**
 * @implements ProcessorInterface<ModerationAction, null>
 */
final readonly class LiftModerationActionProcessor implements ProcessorInterface
{
    public function __construct(
        private ModerationServiceInterface $moderationService,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var ModerationAction $data */
        if (ModerationActionType::ServerBan === $data->getType()) {
            $this->scopeGuard->requireScope('admin');
        }

        $this->moderationService->lift($data);

        return null;
    }
}
