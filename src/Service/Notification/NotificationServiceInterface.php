<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Dto\Notification\CreateNotificationDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;

interface NotificationServiceInterface
{
    /** @internal No authz — caller must gate. */
    public function new(CreateNotificationDto $createNotificationDto): Notification;

    public function get(int $id): Notification;

    /** @return array<string, int> */
    public function getUnreadCounts(): array;

    /** @return Notification[] */
    public function getAllForCommunity(Community $community): array;

    public function markAsRead(Notification $notification): Notification;

    public function markAllAsReadForCommunity(Community $community): void;

    /** @return Notification[] */
    public function getAllDmForCurrentUser(): array;

    public function markAllDmAsRead(): void;

    public function markAllAsRead(): void;

    public function upsertChannelActivity(User $recipient, Channel $channel, int $actorId, string $actorName, string $messageIri): Notification;

    /** @return list<int> */
    public function findRecentMentionerUserIds(User $user, int $limit): array;
}
