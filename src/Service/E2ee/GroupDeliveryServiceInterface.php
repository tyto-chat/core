<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\Dto\E2ee\CommitInputDto;
use App\Dto\E2ee\LeaveRequestInputDto;
use App\Entity\Conversation;
use App\Entity\E2eeGroupEpoch;
use App\Entity\E2eeLeaveRequest;
use App\Entity\E2eeWelcome;

interface GroupDeliveryServiceInterface
{
    public const int MAX_COMMITS_PER_PAGE = 100;
    public const int MAX_WELCOMES_PER_PAGE = 50;

    /** @return array{epoch: E2eeGroupEpoch, created: bool} `created` is false for a resend of a known commit id */
    public function submitCommit(Conversation $conversation, CommitInputDto $commit): array;

    /** @return array{epoch: int, generation: int, groupInfo: ?string, frozenGenerations: list<int>} */
    public function state(Conversation $conversation): array;

    /** @return list<E2eeGroupEpoch> oldest first */
    public function listCommits(Conversation $conversation, int $after): array;

    /** @return list<E2eeWelcome> oldest undelivered welcomes for the caller's active devices; acknowledge them to get the next ones */
    public function listWelcomes(?string $deviceId): array;

    public function acknowledgeWelcome(string $welcomeId): void;

    public function recordLeaveRequest(string $deviceId, LeaveRequestInputDto $request): void;

    /** @return list<E2eeLeaveRequest> pending leave requests of the conversation */
    public function listLeaveRequests(Conversation $conversation): array;

    public function isGenerationFrozen(Conversation $conversation, int $generation): bool;
}
