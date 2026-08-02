<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\Settings\SupportedLocale;
use App\Repository\PushSubscriptionRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;

class PushSubscriptionService extends AbstractDoctrineService implements PushSubscriptionServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly PushSubscriptionRepository $repository,
    ) {
    }

    #[\Override]
    public function subscribe(string $endpoint, string $p256dh, string $authToken, string $locale = 'en'): void
    {
        $caller = $this->security->currentUser('You must be signed in to enable push notifications.');

        $subscription = $this->repository->findOneByEndpoint($endpoint) ?? new PushSubscription();
        $subscription->setUser($caller);
        $subscription->setEndpoint($endpoint);
        $subscription->setP256dh($p256dh);
        $subscription->setAuthToken($authToken);
        $subscription->setLocale(in_array($locale, SupportedLocale::values(), true) ? $locale : 'en');

        $this->save($subscription);
    }

    #[\Override]
    public function unsubscribe(string $endpoint): void
    {
        $caller = $this->security->currentUser('You must be signed in to disable push notifications.');

        $subscription = $this->repository->findOneByEndpoint($endpoint);
        if (null !== $subscription && $subscription->getUser()->getId() === $caller->getId()) {
            $this->removeAndFlush($subscription);
        }
    }

    #[\Override]
    public function countFor(User $user): int
    {
        return $this->repository->countByUser($user);
    }

    #[\Override]
    public function removeAllFor(User $user): void
    {
        $this->repository->deleteAllForUser($user);
    }
}
