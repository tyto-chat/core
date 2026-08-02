<?php

declare(strict_types=1);

namespace App\Enum\ApiKey;

use App\Enum\BackedEnumValuesTrait;

enum ApiKeyScope: string
{
    use BackedEnumValuesTrait;

    case ProfileRead = 'profile:read';
    case ProfileWrite = 'profile:write';
    case MessagesRead = 'messages:read';
    case MessagesWrite = 'messages:write';
    case ConversationsRead = 'conversations:read';
    case ConversationsWrite = 'conversations:write';
    case CommunitiesRead = 'communities:read';
    case CommunitiesWrite = 'communities:write';
    case NotificationsRead = 'notifications:read';
    case NotificationsWrite = 'notifications:write';
    case PushWrite = 'push:write';
    case ModerationRead = 'moderation:read';
    case ModerationWrite = 'moderation:write';
    case Admin = 'admin';
}
