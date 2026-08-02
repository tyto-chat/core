<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Notification;
use App\Enum\Notification\NotificationType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Notification>
 */
final class NotificationFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Notification::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'recipient' => UserFactory::new(),
            'channelIdentifier' => 'general',
            'communityIdentifier' => self::faker()->slug(2),
            'type' => NotificationType::Mention,
            'isRead' => false,
        ];
    }

    public function forRecipient(\App\Entity\User $user): static
    {
        return $this->with(['recipient' => $user]);
    }

    public function inCommunity(\App\Entity\Community $community): static
    {
        return $this->with(['community' => $community, 'communityIdentifier' => $community->getIdentifier()]);
    }
}
