<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Channel;
use App\Entity\User;
use App\Service\Realtime\RealtimePublisherInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class PublishChannelTypingProcessor implements ProcessorInterface
{
    public function __construct(
        private RealtimePublisherInterface $publisher,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var Channel $channel */
        $channel = $context['read_data'];
        /** @var User $user */
        $user = $this->security->getUser();

        $this->publisher->publishChannelTyping($channel, (int) $user->getId(), $user->getProfile()?->getName() ?? 'Unknown');

        return null;
    }
}
