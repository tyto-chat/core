<?php

declare(strict_types=1);

namespace App\State\Invite\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\InviteRedemption;
use App\Service\Community\CommunityInviteServiceInterface;

/**
 * @implements ProcessorInterface<mixed, InviteRedemption>
 */
final readonly class AcceptInviteProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityInviteServiceInterface $inviteService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InviteRedemption
    {
        $token = (string) $uriVariables['token'];
        $community = $this->inviteService->accept($token);

        $resource = new InviteRedemption();
        $resource->token = $token;
        $resource->communityIdentifier = $community->getIdentifier();
        $resource->communityName = $community->getName();

        return $resource;
    }
}
