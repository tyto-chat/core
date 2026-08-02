<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;

interface MessageNotificationDispatcherInterface
{
    public function dispatchFor(Message $message): void;

    public function dispatchForDm(Message $message, Conversation $conversation, User $author): void;
}
