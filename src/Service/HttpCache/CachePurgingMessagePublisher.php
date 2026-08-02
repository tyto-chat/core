<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Repository\MessagePageRepository;
use App\Service\Realtime\MessageRealtimePublisherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: MessageRealtimePublisherInterface::class)]
final class CachePurgingMessagePublisher implements MessageRealtimePublisherInterface
{
    public function __construct(
        private readonly MessageRealtimePublisherInterface $inner,
        private readonly CachePurgerInterface $purger,
        private readonly MessagePageRepository $pages,
    ) {
    }

    #[\Override]
    public function publishChannelActivity(Channel $channel): void
    {
        $this->inner->publishChannelActivity($channel);
        $latest = $this->pages->findLatestForChannel($channel);
        if (null !== $latest) {
            $this->purgeChannel($channel, $latest->getPageNumber());
        }
    }

    #[\Override]
    public function publishMessageUpdated(Message $message, string $body): void
    {
        $this->inner->publishMessageUpdated($message, $body);
        $this->purgeMessagePage($message);
    }

    #[\Override]
    public function publishMessageDeleted(Message $message): void
    {
        $this->inner->publishMessageDeleted($message);
        $this->purgeMessagePage($message);
    }

    #[\Override]
    public function publishMessageReactions(Message $message): void
    {
        $this->inner->publishMessageReactions($message);
        $this->purgeMessagePage($message);
    }

    #[\Override]
    public function publishMessageAttachmentsUpdated(Message $message): void
    {
        $this->inner->publishMessageAttachmentsUpdated($message);
        $this->purgeMessagePage($message);
    }

    #[\Override]
    public function publishMessagePinned(Message $message): void
    {
        $this->inner->publishMessagePinned($message);
        $this->purgeMessagePage($message);
    }

    #[\Override]
    public function publishMessageThreadMeta(Message $root): void
    {
        $this->inner->publishMessageThreadMeta($root);
        $this->purgeMessagePage($root);
    }

    #[\Override]
    public function publishConversationActivity(Conversation $conversation): void
    {
        $this->inner->publishConversationActivity($conversation);
    }

    private function purgeThreadFor(Message $message): void
    {
        $parent = $message->getParent();
        if (null !== $parent) {
            $this->purger->purgeMessageThread($parent->getId());

            return;
        }

        if ($message->getReplyCount() > 0) {
            $this->purger->purgeMessageThread($message->getId());
        }
    }

    private function purgeMessagePage(Message $message): void
    {
        $this->purger->purgeMessage($message->getId());
        $channel = $message->getChannel();
        $page = $message->getPage();
        if (null !== $channel && null !== $page) {
            $this->purgeChannel($channel, $page->getPageNumber());
        }
        $this->purgeThreadFor($message);
    }

    private function purgeChannel(Channel $channel, int $pageNumber): void
    {
        $community = $channel->getCommunity();
        if (null === $community) {
            return;
        }
        $communityIdentifier = (string) $community->getIdentifier();
        $channelIdentifier = (string) $channel->getIdentifier();
        $this->purger->purgeChannelPage($communityIdentifier, $channelIdentifier, $pageNumber);
        // /messages/current and /pinned-messages embed the same messages — must purge together or they serve stale.
        $this->purger->purgeChannelExtras($communityIdentifier, $channelIdentifier);
    }
}
