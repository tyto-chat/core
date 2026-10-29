<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Conversation;
use App\Entity\E2eeGroupEpoch;
use App\Entity\E2eeWelcome;

interface E2eeRealtimePublisherInterface
{
    public function publishMlsCommit(E2eeGroupEpoch $epoch): void;

    public function publishMlsWelcome(E2eeWelcome $welcome): void;

    public function publishMlsLeaveRequest(Conversation $conversation, string $leaveRequestId): void;
}
