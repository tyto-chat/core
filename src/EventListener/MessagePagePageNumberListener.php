<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\MessagePage;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

#[AsDoctrineListener(event: Events::prePersist)]
final class MessagePagePageNumberListener implements ResetInterface
{
    /**
     * MAX+1 can't see persisted-but-unflushed pages — without this cache, two pages in one flush would collide.
     *
     * @var array<string, int>
     */
    private array $minted = [];

    public function __construct(
        private readonly ChannelServiceInterface $channelService,
        private readonly ConversationServiceInterface $conversationService,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof MessagePage) {
            return;
        }

        $channel = $entity->getChannel();
        if (null !== $channel) {
            $entity->setPageNumber($this->next(
                null !== $channel->getId() ? 'c'.$channel->getId() : null,
                fn (): int => $this->channelService->nextPageNumber($channel),
            ));

            return;
        }

        $conversation = $entity->getConversation();
        if (null !== $conversation) {
            $entity->setPageNumber($this->next(
                null !== $conversation->getId() ? 'v'.$conversation->getId() : null,
                fn (): int => $this->conversationService->nextPageNumber($conversation),
            ));
        }
    }

    private function next(?string $key, \Closure $findNext): int
    {
        if (null === $key) {
            return $findNext();
        }

        $number = isset($this->minted[$key]) ? $this->minted[$key] + 1 : $findNext();

        return $this->minted[$key] = $number;
    }

    #[\Override]
    public function reset(): void
    {
        $this->minted = [];
    }
}
