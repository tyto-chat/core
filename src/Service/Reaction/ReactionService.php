<?php

declare(strict_types=1);

namespace App\Service\Reaction;

use App\Dto\Webhook\WebhookEventContext;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\Reaction;
use App\Exception\Channel\ChannelArchivedException;
use App\Exception\Reaction\EmojiNotAllowedException;
use App\Repository\ReactionRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\ConversationVoter;
use App\Security\Voter\ReactionVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Service\Realtime\MessageRealtimePublisherInterface;
use App\Service\Webhook\WebhookEmitterInterface;

class ReactionService extends AbstractDoctrineService implements ReactionServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly ReactionRepository $reactionRepository,
        private readonly MessageRealtimePublisherInterface $publisher,
        private readonly CommunityEmojiServiceInterface $communityEmojiService,
        private readonly WebhookEmitterInterface $webhookEmitter,
    ) {
    }

    private const string UNICODE_EMOJI_PATTERN = '/^[\p{Extended_Pictographic}\p{Regional_Indicator}\x{200D}\x{FE0F}\x{20E3}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}#*0-9]+$/u';

    #[\Override]
    public function new(Message $message, string $emoji): Reaction
    {
        $user = $this->security->currentUser();
        $isShortcode = str_starts_with($emoji, ':') && str_ends_with($emoji, ':');

        $channel = $message->getChannel();
        $conversation = $message->getConversation();

        if (null !== $channel) {
            $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You must be a member of this channel to react to messages.');

            if ($channel->isArchived()) {
                throw new ChannelArchivedException();
            }

            $community = $channel->getCommunity();
            if (null === $community) {
                throw new EmojiNotAllowedException('Channel is not attached to a community.');
            }

            if ($isShortcode) {
                if (null === $this->communityEmojiService->findByShortcode($community, $emoji)) {
                    throw new EmojiNotAllowedException(sprintf('Shortcode "%s" is not registered for this community.', $emoji));
                }
            } elseif (1 !== preg_match(self::UNICODE_EMOJI_PATTERN, $emoji)) {
                throw new EmojiNotAllowedException(sprintf('Reaction "%s" is not a recognised emoji.', $emoji));
            }
        } elseif (null !== $conversation) {
            $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::VIEW, $conversation, 'You must be a member of this conversation to react to messages.');

            if ($isShortcode) {
                throw new EmojiNotAllowedException('Custom emoji are not supported in direct messages.');
            }
            if (1 !== preg_match(self::UNICODE_EMOJI_PATTERN, $emoji)) {
                throw new EmojiNotAllowedException(sprintf('Reaction "%s" is not a recognised emoji.', $emoji));
            }
        } else {
            throw new \LogicException('Message has no container.');
        }

        $existing = $this->reactionRepository->findByMessageAndUser($message, $user, $emoji);
        if ($existing) {
            return $existing;
        }

        $reaction = new Reaction();
        $reaction->setEmoji($emoji);
        $reaction->setMessage($message);
        $reaction = $this->save($reaction);

        $this->rebuildCacheAndPublish($message);

        // DM activity never reaches webhooks.
        if (null !== $channel) {
            $this->webhookEmitter->emit('reaction.added', new WebhookEventContext(
                actor: $user,
                data: [
                    'communityId' => $channel->getCommunity()?->getId(),
                    'channelId' => $channel->getId(),
                    'emoji' => $emoji,
                    'messageId' => $message->getId(),
                    'reactorId' => $user->getId(),
                ],
            ));
        }

        return $reaction;
    }

    #[\Override]
    public function delete(Reaction $reaction): void
    {
        $this->security->throwAccessDeniedUnlessGranted(ReactionVoter::DELETE, $reaction, 'You do not have permission to delete this reaction.');

        if (true === $reaction->getMessage()?->getChannel()?->isArchived()) {
            throw new ChannelArchivedException();
        }

        $message = $reaction->getMessage();

        $this->removeAndFlush($reaction);

        if ($message) {
            $this->rebuildCacheAndPublish($message);
        }
    }

    #[\Override]
    public function removeCommunityReactionsForEmojis(Community $community, array $shortcodes): void
    {
        if ([] === $shortcodes) {
            return;
        }

        $affectedMessages = $this->reactionRepository->findMessagesWithReactions($community, $shortcodes);
        $deleted = $this->reactionRepository->deleteByCommunityAndEmojis($community, $shortcodes);
        $this->logger->info(sprintf('Removed %d reactions for %d emoji(s) in community %d.', $deleted, count($shortcodes), $community->getId()));
        $this->flush();

        foreach ($affectedMessages as $message) {
            $message->setReactions($this->reactionRepository->buildCache($message));
            $this->publisher->publishMessageReactions($message);
        }
        $this->flush();
    }

    private function rebuildCacheAndPublish(Message $message): void
    {
        $message->setReactions($this->reactionRepository->buildCache($message));
        $this->flush();

        $this->publisher->publishMessageReactions($message);
    }
}
