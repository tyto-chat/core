<?php

declare(strict_types=1);

namespace App\State\Moderation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Moderation\CreateModerationActionDto;
use App\Entity\ModerationAction;
use App\Enum\Moderation\ModerationActionType;
use App\Security\ApiKeyScopeGuard;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<CreateModerationActionDto, ModerationAction>
 */
final readonly class CreateModerationActionProcessor implements ProcessorInterface
{
    public function __construct(
        private ModerationServiceInterface $moderationService,
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private UserServiceInterface $userService,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ModerationAction
    {
        /** @var CreateModerationActionDto $data */
        if (ModerationActionType::ServerBan === $data->type) {
            // Server bans are app-wide — a `moderation:write` key must not reach them
            $this->scopeGuard->requireScope('admin');
        }

        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $target = $this->userService->get($data->targetUserId);
        $channel = null !== $data->channelIdentifier
            ? $this->channelService->getByIdentifier($data->channelIdentifier, $community)
            : null;

        return match ($data->type) {
            ModerationActionType::Warn => $this->moderationService->warn($community, $target, $data->reason),
            ModerationActionType::Timeout => $this->moderationService->timeout(
                $community,
                $target,
                $data->reason,
                $data->expiresAt,
                $channel,
            ),
            ModerationActionType::Ban => $this->moderationService->ban(
                $community,
                $target,
                $data->reason,
                $data->expiresAt,
            ),
            ModerationActionType::ServerBan => $this->moderationService->serverBan(
                $community,
                $target,
                $data->reason,
                $data->expiresAt,
            ),
        };
    }
}
