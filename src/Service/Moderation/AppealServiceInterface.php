<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Dto\Appeal\UpdateAppealDto;
use App\Entity\Appeal;
use App\Entity\Community;
use App\Enum\Moderation\AppealStatus;

interface AppealServiceInterface
{
    public function create(int $actionId, string $reason): Appeal;

    /** @internal No authz — caller must gate. */
    public function get(int $id): Appeal;

    /** @return list<Appeal> */
    public function listForCommunity(Community $community, ?AppealStatus $status = null, int $page = 1): array;

    public function resolve(Appeal $appeal, UpdateAppealDto $dto): Appeal;
}
