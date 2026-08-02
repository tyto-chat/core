<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Channel;
use App\Exception\Channel\MissingOrInvalidUserIdException;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<Channel, null>
 */
final readonly class RemoveChannelMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private ChannelMembershipServiceInterface $channelMembershipService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var Channel $data */
        // {userId} has no uriVariables Link, so API Platform omits it from $uriVariables — read the raw route attribute
        $userId = (int) ($uriVariables['userId'] ?? ($context['request'] ?? null)?->attributes->get('userId', 0));
        if (0 === $userId) {
            throw new MissingOrInvalidUserIdException('Missing userId.');
        }

        $user = $this->userService->get($userId);
        $channelMember = $this->channelMembershipService->getMember($data, $user);
        $this->channelMembershipService->removeMember($channelMember);

        return null;
    }
}
