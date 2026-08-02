<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Conversation;
use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Conversation>
 */
final class ConversationFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Conversation::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            // Random hash so every factory call without withParticipants() is
            // unique (the entity carries a unique index on participants_hash).
            'participantsHash' => sha1(uniqid('conv-', true)),
        ];
    }

    /**
     * Set the canonical hash for the given participant set.
     *
     * @param User[] $users
     */
    public function withParticipants(array $users): static
    {
        $ids = [];
        foreach ($users as $u) {
            $id = $u->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $this->with(['participantsHash' => Conversation::hashParticipants($ids)]);
    }
}
