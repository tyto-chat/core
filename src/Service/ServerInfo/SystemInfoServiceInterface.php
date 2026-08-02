<?php

declare(strict_types=1);

namespace App\Service\ServerInfo;

use App\Dto\Stats\CommunityStatsDto;
use App\Dto\Stats\SystemInfoDto;

interface SystemInfoServiceInterface
{
    public function getSystemInfo(): SystemInfoDto;

    /**
     * @return CommunityStatsDto[]
     */
    public function getCommunityStats(): array;
}
