<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ConversationMember>
 */
final class ConversationMemberFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return ConversationMember::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'user' => UserFactory::new(),
            'conversation' => ConversationFactory::new(),
        ];
    }

    public static function createForUserAndConversation(User $user, Conversation $conversation): ConversationMember
    {
        return self::createOne([
            'conversation' => $conversation,
            'user' => $user,
        ]);
    }
}
