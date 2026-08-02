<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Exception\Moderation\ImmuneTargetException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

interface ModerationServiceInterface
{
    /**
     * @throws AccessDeniedException if actor lacks permission
     * @throws ImmuneTargetException if target is immune
     */
    public function warn(Community $community, User $target, ?string $reason = null): ModerationAction;

    /**
     * @throws AccessDeniedException if actor lacks permission
     * @throws ImmuneTargetException if target is immune
     */
    public function timeout(
        Community $community,
        User $target,
        ?string $reason,
        \DateTimeInterface $expiresAt,
        ?Channel $channel = null,
    ): ModerationAction;

    /**
     * @throws AccessDeniedException if actor lacks permission
     * @throws ImmuneTargetException if target is immune
     */
    public function ban(
        Community $community,
        User $target,
        ?string $reason,
        ?\DateTimeInterface $expiresAt,
    ): ModerationAction;

    /**
     * @throws AccessDeniedException if actor is not a global admin
     * @throws ImmuneTargetException if target is immune
     */
    public function serverBan(
        Community $community,
        User $target,
        ?string $reason,
        ?\DateTimeInterface $expiresAt,
    ): ModerationAction;

    /** @internal No authz — caller must gate. */
    public function isServerBanned(User $user): bool;

    /**
     * @throws AccessDeniedException if actor lacks scope permission
     */
    public function lift(ModerationAction $action): ModerationAction;

    /** @internal No authz — caller must gate. */
    public function isTimedOut(Community $community, Channel $channel, User $user): bool;

    /** @internal No authz — caller must gate. */
    public function isBanned(Community $community, User $user): bool;

    /**
     * @return ModerationAction[]
     */
    public function getActiveActions(Community $community, User $user): array;

    /**
     * @return ModerationAction[]
     */
    public function getLog(
        Community $community,
        int $page = 1,
        ?Channel $channelFilter = null,
        ?string $typeFilter = null,
        bool $activeOnly = false,
        ?User $targetUser = null,
    ): array;

    public function countLog(
        Community $community,
        ?Channel $channelFilter = null,
        ?string $typeFilter = null,
        bool $activeOnly = false,
        ?User $targetUser = null,
    ): int;

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException if not found
     * @throws AccessDeniedException                                         if actor lacks permission
     */
    public function getAction(int $id, Community $community): ModerationAction;
}
