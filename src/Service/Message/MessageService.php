<?php

declare(strict_types=1);

namespace App\Service\Message;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Async\DispatchMessageNotificationsMessage;
use App\Dto\Message\UpdateMessageDto;
use App\Dto\Webhook\WebhookEventContext;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\MessageRevision;
use App\Enum\Community\BroadcastMentionRole;
use App\Enum\Message\MessageKind;
use App\Exception\Channel\ChannelArchivedException;
use App\Exception\Conversation\NotMemberException;
use App\Exception\Message\BroadcastNotAllowedException;
use App\Exception\Message\CannotReplyToReplyException;
use App\Exception\Message\InvalidAttachmentException;
use App\Exception\Message\MessageAlreadyPinnedException;
use App\Exception\Message\MessageNotFoundException;
use App\Exception\Message\MessageNotPinnedException;
use App\Exception\Message\PinNotAllowedException;
use App\Exception\Message\ThreadNotAllowedException;
use App\Exception\Message\TooManyAttachmentsException;
use App\Exception\Message\TooManyPinnedMessagesException;
use App\Exception\Moderation\TimedOutException;
use App\Repository\MessagePageRepository;
use App\Repository\MessageRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\ConversationVoter;
use App\Security\Voter\MessageVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\Realtime\MessageRealtimePublisherInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookEmitterInterface;
use App\Settings\Settings;
use App\Utils\MarkdownSanitizer;
use App\Utils\MentionExtractor;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Messenger\MessageBusInterface;

class MessageService extends AbstractDoctrineService implements MessageServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly MessageRepository $messageRepository,
        private readonly ChannelServiceInterface $channelService,
        private readonly ChannelMembershipServiceInterface $channelMembershipService,
        #[Lazy]
        private readonly ConversationServiceInterface $conversationService,
        private readonly MessageNotificationDispatcherInterface $notificationDispatcher,
        private readonly MessageRealtimePublisherInterface $publisher,
        private readonly IriConverterInterface $iriConverter,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly ModerationServiceInterface $moderationService,
        private readonly WebhookEmitterInterface $webhookEmitter,
        private readonly MessageBusInterface $messageBus,
        private readonly CachePurgerInterface $cachePurger,
        private readonly MessagePageRepository $messagePages,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[\Override]
    public function sendToChannel(Channel $channel, string $text, array $attachmentIris = [], MessageKind $kind = MessageKind::Standard): Message
    {
        $text = MarkdownSanitizer::sanitize($text);
        $isSystem = MessageKind::System === $kind;

        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::POST, $channel, 'You do not have permission to post in this channel.');

        if ($channel->isArchived()) {
            throw new ChannelArchivedException();
        }

        $user = $this->security->currentUser();
        $community = $channel->getCommunity();
        if (!$isSystem && null !== $community && $this->moderationService->isTimedOut($community, $channel, $user)) {
            throw new TimedOutException('You are timed out and cannot post messages.');
        }

        if (!$isSystem) {
            $this->assertCanBroadcast($channel, MentionExtractor::extractBroadcasts($text));
        }

        $attachments = $this->resolveAttachments($attachmentIris);

        $page = $this->channelService->findLatestPage($channel);
        if (!$page || $this->messageRepository->countRootsInPage($page) >= MessagePage::PAGE_SIZE) {
            $page = new MessagePage();
            $page->setChannel($channel);
            $this->persist($page);
        }

        $message = $this->buildAndSave($page, $text, $kind);
        $this->linkAttachments($message, $attachments, publishUpdate: false);

        if (!$isSystem) {
            $this->publisher->publishChannelActivity($channel);
            if ([] !== $attachments) {
                $this->publisher->publishMessageAttachmentsUpdated($message);
            }
            $this->messageBus->dispatch(new DispatchMessageNotificationsMessage($message->getId()));

            $author = $message->getCreatedBy();
            $this->webhookEmitter->emit('message.created', new WebhookEventContext(
                actor: $author,
                data: [
                    'communityId' => $community?->getId(),
                    'channelId' => $channel->getId(),
                    'messageId' => $message->getId(),
                    'messageText' => $message->getText(),
                    'authorId' => $author?->getId(),
                    'authorName' => $message->getCreatedBy()?->getProfile()?->getName() ?? 'Unknown',
                    'createdAt' => $message->getCreatedAt()?->getTimestamp(),
                ],
            ));
        } else {
            // System sends skip every publish the cache-purging decorator hooks — purge directly or the cached page misses the message.
            if (null !== $community) {
                $latest = $this->messagePages->findLatestForChannel($channel);
                if (null !== $latest) {
                    $communityIdentifier = (string) $community->getIdentifier();
                    $channelIdentifier = (string) $channel->getIdentifier();
                    $this->cachePurger->purgeChannelPage($communityIdentifier, $channelIdentifier, $latest->getPageNumber());
                    $this->cachePurger->purgeChannelExtras($communityIdentifier, $channelIdentifier);
                }
            }
        }

        return $message;
    }

    #[\Override]
    public function sendToConversation(Conversation $conversation, string $text, array $attachmentIris = []): Message
    {
        $text = MarkdownSanitizer::sanitize($text);

        $caller = $this->security->currentUser('You must be signed in to send messages.');
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::WRITE, $conversation, 'You are not a member of this conversation.');

        if (!$this->conversationService->isMember($conversation, $caller)) {
            throw new NotMemberException();
        }

        $attachments = $this->resolveAttachments($attachmentIris);

        $page = $this->conversationService->findLatestPage($conversation);
        if (!$page || $this->messageRepository->countRootsInPage($page) >= MessagePage::PAGE_SIZE) {
            $page = new MessagePage();
            $page->setConversation($conversation);
            $this->persist($page);
        }

        $message = $this->buildAndSave($page, $text);
        $this->linkAttachments($message, $attachments, publishUpdate: false);

        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $this->save($conversation);

        $this->publisher->publishConversationActivity($conversation);
        if ([] !== $attachments) {
            $this->publisher->publishMessageAttachmentsUpdated($message);
        }
        $this->notificationDispatcher->dispatchForDm($message, $conversation, $caller);

        return $message;
    }

    private function buildAndSave(MessagePage $page, string $text, MessageKind $kind = MessageKind::Standard): Message
    {
        $revision = new MessageRevision();
        $revision->setText($text);
        $this->persist($revision);

        $message = new Message();
        $message->addRevision($revision);
        $message->setPage($page);
        $message->setMentionedUserIds(MentionExtractor::extractUserIds($text));
        $message->setText($text);
        $message->setKind($kind);

        return $this->save($message);
    }

    #[\Override]
    public function update(Message $message, UpdateMessageDto $updateMessageDto): Message
    {
        $this->security->throwAccessDeniedUnlessGranted(MessageVoter::UPDATE, $message, 'You do not have permission to edit this message.');

        $text = MarkdownSanitizer::sanitize($updateMessageDto->text);

        $revision = new MessageRevision();
        $revision->setText($text);
        $this->persist($revision);
        $message->addRevision($revision);
        $message->setMentionedUserIds(MentionExtractor::extractUserIds($text));
        $message->setText($text);

        $message = $this->save($message);

        $this->publisher->publishMessageUpdated($message, $text);

        return $message;
    }

    #[\Override]
    public function getById(string $uuid): Message
    {
        return $this->getByCriteria(['id' => $uuid]);
    }

    #[\Override]
    public function countIndexableMessages(): int
    {
        return $this->messageRepository->countNotDeleted();
    }

    #[\Override]
    public function findIndexableMessagesBatch(int $offset, int $limit): array
    {
        return $this->messageRepository->findNotDeletedBatch($offset, $limit);
    }

    #[\Override]
    public function hydrateText(Message $message): void
    {
        $lastRevision = $message->getRevisions()->last();
        $message->setText(
            $message->isDeleted() || false === $lastRevision ? null : $lastRevision->getText()
        );
    }

    #[\Override]
    public function getForHistory(string $uuid): Message
    {
        $message = $this->getByCriteria(['id' => $uuid]);

        if ($this->security->isAdmin()) {
            return $message;
        }

        $channel = $message->getChannel();
        $this->security->throwAccessDeniedIf(null === $channel, 'Only admins can view this message\'s history.');

        $this->security->throwAccessDeniedUnlessGranted(
            ChannelVoter::MODERATE,
            $channel,
            'You do not have permission to view this message\'s history.',
        );

        return $message;
    }

    #[\Override]
    public function delete(Message $message): void
    {
        $this->security->throwAccessDeniedUnlessGranted(MessageVoter::DELETE, $message, 'You do not have permission to delete this message.');

        $this->mediaObjectService->deleteMessageAttachments($message);

        $message->setDeleted(true);
        $message->setDeletedAt(new \DateTime());
        $message->setDeletedBy($this->security->currentUser());
        $this->save($message);

        $this->publisher->publishMessageDeleted($message);

        // Deleted replies stay counted in the root's replyCount — tombstones render in place.
    }

    #[\Override]
    public function reply(Message $root, string $text, array $attachmentIris = []): Message
    {
        $text = MarkdownSanitizer::sanitize($text);

        $channel = $root->getChannel();
        $conversation = $root->getConversation();
        if (null === $channel && null === $conversation) {
            throw new ThreadNotAllowedException();
        }
        if (null !== $root->getParent()) {
            throw new CannotReplyToReplyException();
        }
        if (MessageKind::System === $root->getKind()) {
            throw new ThreadNotAllowedException();
        }

        $user = $this->security->currentUser();

        if (null !== $channel) {
            $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::REPLY, $channel, 'You do not have permission to reply in this channel.');

            $community = $channel->getCommunity();
            if (null !== $community && $this->moderationService->isTimedOut($community, $channel, $user)) {
                throw new TimedOutException('You are timed out and cannot post messages.');
            }

            $this->assertCanBroadcast($channel, MentionExtractor::extractBroadcasts($text));
        } else {
            $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::WRITE, $conversation, 'You are not a member of this conversation.');
            if (!$this->conversationService->isMember($conversation, $user)) {
                throw new NotMemberException();
            }
        }

        $page = $root->getPage();
        if (null === $page) {
            throw new ThreadNotAllowedException();
        }

        $attachments = $this->resolveAttachments($attachmentIris);

        // save() flushes individually — without the wrapping transaction a mid-sequence failure leaves a half-created thread.
        $reply = $this->transactional(function () use ($text, $page, $root, $attachments): Message {
            $revision = new MessageRevision();
            $revision->setText($text);
            $this->persist($revision);

            $reply = new Message();
            $reply->addRevision($revision);
            $reply->setPage($page);
            $reply->setParent($root);
            $reply->setMentionedUserIds(MentionExtractor::extractUserIds($text));
            $reply->setText($text);
            $reply = $this->save($reply);

            $this->linkAttachments($reply, $attachments, publishUpdate: false);

            $root->incrementReplyCount();
            $root->setLastReplyAt(new \DateTimeImmutable());
            $this->save($root);

            return $reply;
        });

        $this->publisher->publishMessageThreadMeta($root);

        if (null !== $channel) {
            $this->messageBus->dispatch(new DispatchMessageNotificationsMessage($reply->getId()));

            $replyAuthor = $reply->getCreatedBy();
            $replyCommunity = $channel->getCommunity();
            $this->webhookEmitter->emit('message.replied', new WebhookEventContext(
                actor: $replyAuthor,
                data: [
                    'communityId' => $replyCommunity?->getId(),
                    'channelId' => $channel->getId(),
                    'messageId' => $reply->getId(),
                    'messageText' => $reply->getText(),
                    'authorId' => $replyAuthor?->getId(),
                    'authorName' => $reply->getCreatedBy()?->getProfile()?->getName() ?? 'Unknown',
                    'createdAt' => $reply->getCreatedAt()?->getTimestamp(),
                    'rootMessageId' => $root->getId(),
                ],
            ));
        } else {
            // Never webhooks for DM replies (content must not leave participants) and deliberately no lastMessageAt bump — thread-scoped.
            $this->notificationDispatcher->dispatchForDm($reply, $conversation, $user);
        }

        return $reply;
    }

    /**
     * @param list<string> $attachmentIris
     *
     * @return list<MediaObject>
     */
    private function resolveAttachments(array $attachmentIris): array
    {
        $max = $this->settings->get(Settings::maxAttachmentsPerMessage());
        if (count($attachmentIris) > $max) {
            throw new TooManyAttachmentsException(sprintf('A message can have at most %d attachment(s).', $max));
        }

        $attachments = [];
        foreach ($attachmentIris as $iri) {
            $mediaObject = $this->iriConverter->getResourceFromIri($iri);
            if (!$mediaObject instanceof MediaObject || 'attachment' !== $mediaObject->type) {
                throw new InvalidAttachmentException(sprintf('IRI "%s" is not a valid attachment.', $iri));
            }
            $attachments[] = $mediaObject;
        }

        return $attachments;
    }

    /**
     * @param list<MediaObject> $attachments
     */
    private function linkAttachments(Message $message, array $attachments, bool $publishUpdate): void
    {
        foreach ($attachments as $attachment) {
            $this->mediaObjectService->linkAttachmentToMessage($attachment, $message);
        }

        if ($publishUpdate && [] !== $attachments) {
            $this->publisher->publishMessageAttachmentsUpdated($message);
        }
    }

    /** @return Message[] */
    #[\Override]
    public function findThreadReplies(Message $root, int $limit = 50, ?Message $before = null): array
    {
        $this->security->throwAccessDeniedUnlessGranted(MessageVoter::VIEW, $root, 'You do not have access to this message.');

        $replies = $this->messageRepository->findChildren($root, $limit, $before);
        foreach ($replies as $reply) {
            $this->hydrateText($reply);
        }

        return $replies;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function getByCriteria(array $criteria): Message
    {
        $message = $this->messageRepository->findOneBy($criteria);
        if (!$message) {
            $logMessage = sprintf('Message not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR));
            $this->logger->warning($logMessage);

            throw new MessageNotFoundException($logMessage);
        }

        $this->security->throwAccessDeniedUnlessGranted(MessageVoter::VIEW, $message, 'You do not have access to this message.');

        return $message;
    }

    /**
     * @param string[] $broadcasts
     *
     * @throws BroadcastNotAllowedException
     */
    private function assertCanBroadcast(Channel $channel, array $broadcasts): void
    {
        if ([] === $broadcasts) {
            return;
        }
        $required = $channel->getCommunity()?->getBroadcastMentionMinRole() ?? BroadcastMentionRole::Member;
        if ($this->callerBroadcastRank($channel) < $required->rank()) {
            throw new BroadcastNotAllowedException();
        }
    }

    private function callerBroadcastRank(Channel $channel): int
    {
        if ($this->security->isAdmin()) {
            return BroadcastMentionRole::Admin->rank();
        }
        $user = $this->security->currentUser();
        $community = $channel->getCommunity();
        if (null !== $community) {
            if ($this->communityMembership->isAdmin($user, $community)) {
                return BroadcastMentionRole::Admin->rank();
            }
            if ($this->communityMembership->isModerator($user, $community)) {
                return BroadcastMentionRole::Moderator->rank();
            }
        }
        if ($this->channelMembershipService->isChannelModerator($user, $channel)) {
            return BroadcastMentionRole::Moderator->rank();
        }

        return BroadcastMentionRole::Member->rank();
    }

    #[\Override]
    public function countNewInConversationFor(
        Conversation $conversation,
        \App\Entity\User $excluding,
        ?\DateTimeImmutable $since,
    ): int {
        return $this->messageRepository->countNewInConversationFor($conversation, $excluding, $since);
    }

    #[\Override]
    public function countUnreadPerConversationFor(\App\Entity\User $user): array
    {
        return $this->messageRepository->countUnreadPerConversationFor($user);
    }

    /** @return Message[] */
    #[\Override]
    public function getRootsByPage(MessagePage $page): array
    {
        // An empty channel or conversation has no page row yet, so callers hand
        // us a transient placeholder. Doctrine refuses to bind an entity without
        // an identifier as a query parameter — and by definition it has no
        // messages, so there is nothing to look up.
        if (null === $page->getId()) {
            return [];
        }

        return $this->messageRepository->findRootsByPage($page);
    }

    /** @return Message[] */
    #[\Override]
    public function findPinnedForChannel(Channel $channel): array
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You do not have access to this channel.');

        $messages = $this->messageRepository->findPinnedForChannel($channel);
        foreach ($messages as $message) {
            $this->hydrateText($message);
        }

        return $messages;
    }

    #[\Override]
    public function pin(Message $message): Message
    {
        $channel = $message->getChannel();
        if (null === $channel || null !== $message->getParent() || MessageKind::System === $message->getKind()) {
            throw new PinNotAllowedException();
        }
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::PIN, $channel, 'You do not have permission to pin messages in this channel.');

        if ($message->isPinned()) {
            throw new MessageAlreadyPinnedException();
        }
        if ($this->messageRepository->countPinnedForChannel($channel) >= self::MAX_PINNED_PER_CHANNEL) {
            throw new TooManyPinnedMessagesException(self::MAX_PINNED_PER_CHANNEL);
        }

        $message->setPinnedAt(new \DateTimeImmutable());
        $message->setPinnedBy($this->security->currentUser());
        $message = $this->save($message);

        $this->hydrateText($message);
        $this->publisher->publishMessagePinned($message);

        return $message;
    }

    #[\Override]
    public function unpin(Message $message): Message
    {
        $channel = $message->getChannel();
        if (null === $channel) {
            throw new PinNotAllowedException();
        }
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::PIN, $channel, 'You do not have permission to pin messages in this channel.');

        if (!$message->isPinned()) {
            throw new MessageNotPinnedException();
        }

        $message->setPinnedAt(null);
        $message->setPinnedBy(null);
        $message = $this->save($message);

        $this->hydrateText($message);
        $this->publisher->publishMessagePinned($message);

        return $message;
    }
}
