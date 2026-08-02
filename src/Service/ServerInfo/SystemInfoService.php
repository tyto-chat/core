<?php

declare(strict_types=1);

namespace App\Service\ServerInfo;

use App\Dto\Stats\CommunityStatsDto;
use App\Dto\Stats\SystemInfoDto;
use App\Security\SecurityContext;
use App\Service\Community\CommunityServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;

final class SystemInfoService implements SystemInfoServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityServiceInterface $communityService,
        private readonly MediaObjectServiceInterface $mediaObjectService,
    ) {
    }

    public function getSystemInfo(): SystemInfoDto
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only admins can view system info.');

        $memInfo = $this->parseMemInfo();
        $load = sys_getloadavg() ?: [0.0, 0.0, 0.0];

        return new SystemInfoDto(
            cpuCount: $this->parseCpuCount(),
            cpuLoad1: round($load[0], 2),
            cpuLoad5: round($load[1], 2),
            cpuLoad15: round($load[2], 2),
            memoryTotal: $memInfo['total'],
            memoryUsed: $memInfo['total'] - $memInfo['available'],
            memoryFree: $memInfo['available'],
            diskTotal: (int) disk_total_space('/'),
            diskFree: (int) disk_free_space('/'),
            mediaSize: $this->mediaObjectService->getTotalStoredBytes(),
        );
    }

    /**
     * @return CommunityStatsDto[]
     */
    public function getCommunityStats(): array
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only admins can view community stats.');

        return array_map(
            static fn (array $row): CommunityStatsDto => new CommunityStatsDto(
                id: $row['id'],
                identifier: $row['identifier'],
                name: $row['name'],
                channelCount: $row['channelCount'],
                memberCount: $row['memberCount'],
                messageCount: $row['messageCount'],
                attachmentCount: $row['attachmentCount'],
                attachmentsSize: $row['attachmentsSize'],
            ),
            $this->communityService->findAllWithStats(),
        );
    }

    private function parseCpuCount(): int
    {
        $content = (string) @file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor\s*:/m', $content, $matches);

        return max(1, count($matches[0]));
    }

    /**
     * @return array{total: int, available: int}
     */
    private function parseMemInfo(): array
    {
        $content = (string) @file_get_contents('/proc/meminfo');

        preg_match('/MemTotal:\s+(\d+)\s+kB/', $content, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)\s+kB/', $content, $availMatch);

        $totalKb = isset($totalMatch[1]) ? (int) $totalMatch[1] : 0;
        $availableKb = isset($availMatch[1]) ? (int) $availMatch[1] : 0;

        return [
            'total' => $totalKb * 1024,
            'available' => $availableKb * 1024,
        ];
    }
}
