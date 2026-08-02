<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Async\SendWebPushMessage;
use App\Dto\Notification\CreateNotificationDto;
use App\Dto\Notification\UpdateNotificationDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use App\Exception\Notification\NotificationNotFoundException;
use App\Repository\NotificationRepository;
use App\Security\SecurityContext;
use App\Security\Voter\NotificationVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Realtime\RealtimePublisherInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

class NotificationService extends AbstractDoctrineService implements NotificationServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly NotificationRepository $notificationRepository,
        private readonly RealtimePublisherInterface $publisher,
        private readonly MessageBusInterface $bus,
        private readonly WebPushSenderInterface $webPushSender,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    #[\Override]
    public function new(CreateNotificationDto $createNotificationDto): Notification
    {
        $notification = new Notification();
        $notification = $this->save($notification, $createNotificationDto);

        $this->publisher->publishNotification($notification);
        $this->dispatchWebPush($notification);

        return $notification;
    }

    #[\Override]
    public function get(int $id): Notification
    {
        return $this->getByCriteria(['id' => $id]);
    }

    /**
     * @return array<string, int>
     */
    #[\Override]
    public function getUnreadCounts(): array
    {
        $user = $this->security->currentUser('You must be signed in to view notifications.');

        return $this->notificationRepository->countUnreadByRecipient($user);
    }

    /**
     * @return Notification[]
     */
    #[\Override]
    public function getAllForCommunity(Community $community): array
    {
        $user = $this->security->currentUser('You must be signed in to view notifications.');

        return $this->notificationRepository->findByRecipientAndCommunity($user, $community);
    }

    #[\Override]
    public function markAsRead(Notification $notification): Notification
    {
        $this->security->throwAccessDeniedUnlessGranted(NotificationVoter::UPDATE, $notification, 'You do not have permission to update this notification.');

        return $this->save($notification, new UpdateNotificationDto(isRead: true));
    }

    #[\Override]
    public function markAllAsReadForCommunity(Community $community): void
    {
        $user = $this->security->currentUser('You must be signed in to update notifications.');

        $this->notificationRepository->markAllReadForCommunity($user, $community);
    }

    /** @return Notification[] */
    #[\Override]
    public function getAllDmForCurrentUser(): array
    {
        $user = $this->security->currentUser('You must be signed in to view notifications.');

        return $this->notificationRepository->findDmByRecipient($user);
    }

    #[\Override]
    public function markAllDmAsRead(): void
    {
        $user = $this->security->currentUser('You must be signed in to update notifications.');

        $this->notificationRepository->markAllDmReadForUser($user);
    }

    #[\Override]
    public function markAllAsRead(): void
    {
        $user = $this->security->currentUser('You must be signed in to update notifications.');

        $this->notificationRepository->markAllReadForUser($user);
    }

    #[\Override]
    public function upsertChannelActivity(User $recipient, Channel $channel, int $actorId, string $actorName, string $messageIri): Notification
    {
        $existing = $this->notificationRepository->findOpenChannelActivity($recipient, $channel);
        if (null === $existing) {
            $community = $channel->getCommunity();
            $notification = new Notification();
            $notification->setRecipient($recipient);
            $notification->setCommunity($community);
            $notification->setCommunityIdentifier($community?->getIdentifier() ?? '');
            $notification->setChannelIdentifier($channel->getIdentifier() ?? '');
            $notification->setType(NotificationType::ChannelActivity);
            $notification->setAuthorName($actorName);
            $notification->setMessageIri($messageIri);
            $notification->setActorIds([$actorId]);
            $notification->setMessageCount(1);
            $notification->setCoalesceKey(sprintf(
                '%d:%s:%s',
                (int) $recipient->getId(),
                $community?->getIdentifier() ?? '',
                $channel->getIdentifier() ?? '',
            ));
            $this->persist($notification);
            try {
                $this->flush();
            } catch (UniqueConstraintViolationException $e) {
                // Lost the coalesce-key insert race; EM closed on failed flush — reset + re-resolve detached entities.
                $this->managerRegistry->resetManager();
                $freshRecipient = $this->entityManager->find(User::class, (int) $recipient->getId());
                $freshChannel = $this->entityManager->find(Channel::class, (int) $channel->getId());
                $existing = null !== $freshRecipient && null !== $freshChannel
                    ? $this->notificationRepository->findOpenChannelActivity($freshRecipient, $freshChannel)
                    : null;
                if (null === $existing) {
                    throw $e;
                }

                return $this->coalesceInto($existing, $actorId, $actorName, $messageIri);
            }
            $this->publisher->publishNotification($notification);
            $this->dispatchWebPush($notification);

            return $notification;
        }

        return $this->coalesceInto($existing, $actorId, $actorName, $messageIri);
    }

    private function coalesceInto(Notification $existing, int $actorId, string $actorName, string $messageIri): Notification
    {
        $actorIds = $existing->getActorIds() ?? [];
        if (!in_array($actorId, $actorIds, true)) {
            $actorIds[] = $actorId;
            $existing->setActorIds(array_values($actorIds));
            $existing->setAuthorName($actorName);
        }
        $existing->setMessageCount($existing->getMessageCount() + 1);
        $existing->setMessageIri($messageIri);
        $this->persist($existing);
        $this->flush();

        $this->publisher->publishNotificationUpdated($existing);

        return $existing;
    }

    private function dispatchWebPush(Notification $notification): void
    {
        if (!$this->webPushSender->isConfigured()) {
            return;
        }
        $recipientId = $notification->getRecipient()?->getId();
        if (null === $recipientId) {
            return;
        }

        $conversation = $notification->getConversationIdentifier();
        $tag = null !== $conversation
            ? 'dm:'.$conversation
            : $notification->getCommunityIdentifier().':'.$notification->getChannelIdentifier();

        [$bodyKey, $bodyParams] = $this->buildPushBody($notification);

        $this->bus->dispatch(new SendWebPushMessage(
            $recipientId,
            $bodyKey,
            $bodyParams,
            $this->buildPushUrl($notification),
            $tag,
        ));
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildPushBody(Notification $notification): array
    {
        $params = [
            '%author%' => $notification->getAuthorName(),
            '%channel%' => $notification->getChannelIdentifier(),
        ];

        $key = match ($notification->getType()) {
            NotificationType::DmMessage => 'notification.push.dm_message',
            NotificationType::BroadcastMention => 'notification.push.broadcast_mention',
            NotificationType::ChannelActivity => 'notification.push.channel_activity',
            NotificationType::ChannelAccess => 'notification.push.channel_access',
            NotificationType::ChannelModerator => 'notification.push.channel_moderator',
            NotificationType::GroupAdded => 'notification.push.group_added',
            NotificationType::GroupRemoved => 'notification.push.group_removed',
            NotificationType::GroupOwnershipTransferred => 'notification.push.group_ownership_transferred',
            NotificationType::ReportFiled => 'notification.push.report_filed',
            NotificationType::ReportEscalated => 'notification.push.report_escalated',
            NotificationType::ReportResolved => 'notification.push.report_resolved',
            NotificationType::ReportDismissed => 'notification.push.report_dismissed',
            NotificationType::AppealFiled => 'notification.push.appeal_filed',
            NotificationType::AppealUpheld => 'notification.push.appeal_upheld',
            NotificationType::AppealOverturned => 'notification.push.appeal_overturned',
            NotificationType::DiskPressurePurge => 'notification.push.disk_pressure_purge',
            default => 'notification.push.mention',
        };

        return [$key, $params];
    }

    private function buildPushUrl(Notification $notification): string
    {
        $conversation = $notification->getConversationIdentifier();
        if (null !== $conversation) {
            return '/dm/'.$conversation;
        }

        $iri = $notification->getMessageIri();
        if (null !== $iri && '' !== $iri) {
            $parts = array_values(array_filter(explode('/', $iri), static fn (string $p): bool => '' !== $p));
            $uuid = $parts[array_key_last($parts)] ?? null;
            if (null !== $uuid) {
                return '/m/'.$uuid;
            }
        }

        return '/';
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function getByCriteria(array $criteria): Notification
    {
        $notification = $this->notificationRepository->findOneBy($criteria);
        if (!$notification) {
            $logMessage = sprintf('Notification not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR));
            $this->logger->warning($logMessage);

            throw new NotificationNotFoundException($logMessage);
        }

        $this->security->throwAccessDeniedUnlessGranted(NotificationVoter::VIEW, $notification, 'You do not have access to this notification.');

        return $notification;
    }
}
