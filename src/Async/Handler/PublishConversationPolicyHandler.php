<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\PublishConversationPolicyMessage;
use App\Service\Conversation\ConversationServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishConversationPolicyHandler
{
    public function __construct(
        private readonly ConversationServiceInterface $conversations,
    ) {
    }

    public function __invoke(PublishConversationPolicyMessage $message): void
    {
        $this->conversations->publishServerPolicyChange();
    }
}
