<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;

interface PushSubscriptionServiceInterface
{
    public function subscribe(string $endpoint, string $p256dh, string $authToken, string $locale = 'en'): void;

    public function unsubscribe(string $endpoint): void;

    /** No authz — caller must gate. */
    public function removeAllFor(User $user): void;

    public function countFor(User $user): int;
}
