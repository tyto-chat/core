<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\MessagePage;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<MessagePage>
 */
final class MessagePageFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return MessagePage::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'channel' => ChannelFactory::new(),
        ];
    }

    public function forChannel(Channel $channel): static
    {
        return $this->with(['channel' => $channel]);
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->with(['channel' => null, 'conversation' => $conversation]);
    }
}
