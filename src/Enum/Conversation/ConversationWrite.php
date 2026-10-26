<?php

declare(strict_types=1);

namespace App\Enum\Conversation;

enum ConversationWrite
{
    case Send;
    case Reply;
    case Edit;
    case React;
    case Unreact;
    case Attach;
    case Typing;
    case DeleteMessage;
}
