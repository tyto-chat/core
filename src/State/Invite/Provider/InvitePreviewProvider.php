<?php

declare(strict_types=1);

namespace App\State\Invite\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\InviteRedemption;
use App\Service\Community\CommunityInviteServiceInterface;

/**
 * @implements ProviderInterface<InviteRedemption>
 */
final readonly class InvitePreviewProvider implements ProviderInterface
{
    public function __construct(
        private CommunityInviteServiceInterface $inviteService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InviteRedemption
    {
        $token = (string) $uriVariables['token'];
        $community = $this->inviteService->previewByToken($token)->getCommunity();

        $resource = new InviteRedemption();
        $resource->token = $token;
        $resource->communityIdentifier = $community->getIdentifier();
        $resource->communityName = $community->getName();

        return $resource;
    }
}
