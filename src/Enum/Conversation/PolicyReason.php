<?php

declare(strict_types=1);

namespace App\Enum\Conversation;

enum PolicyReason: string
{
    case OperatorRequired = 'operator_required';
    case SelfRefuses = 'self_refuses';
    case ParticipantRefuses = 'participant_refuses';
    case GroupNotAvailable = 'group_not_available';
    case OperatorDisabled = 'operator_disabled';
    case EncryptionAvailable = 'encryption_available';
}
