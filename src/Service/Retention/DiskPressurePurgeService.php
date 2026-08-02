<?php

declare(strict_types=1);

namespace App\Service\Retention;

use App\Dto\Notification\CreateNotificationDto;
use App\Enum\Admin\AdminAuditAction;
use App\Enum\Notification\NotificationType;
use App\Repository\MediaObjectRepository;
use App\Service\AbstractDoctrineService;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Settings\Settings;

class DiskPressurePurgeService extends AbstractDoctrineService implements DiskPressurePurgeServiceInterface
{
    private const int BATCH_SIZE = 25;

    public function __construct(
        private readonly SettingsServiceInterface $settings,
        private readonly DiskSpaceProbeInterface $diskSpaceProbe,
        private readonly MediaObjectRepository $mediaObjectRepository,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly AdminAuditLoggerInterface $auditLogger,
        private readonly UserServiceInterface $userService,
        private readonly NotificationServiceInterface $notificationService,
    ) {
    }

    #[\Override]
    public function purge(): ?array
    {
        $trigger = (int) $this->settings->get(Settings::diskPurgeTriggerPercent());
        if ($trigger <= 0) {
            return null;
        }

        $total = $this->diskSpaceProbe->totalBytes();
        if ($total <= 0) {
            return null;
        }

        $freePercent = $this->freePercent($total);
        if ($freePercent >= $trigger) {
            return null;
        }

        $target = max((int) $this->settings->get(Settings::diskPurgeTargetPercent()), $trigger);
        $cutoff = (new \DateTimeImmutable())->modify(
            sprintf('-%d days', (int) $this->settings->get(Settings::diskPurgeMinAgeDays())),
        );
        $includeDms = (bool) $this->settings->get(Settings::diskPurgeIncludeDms());

        $files = 0;
        $bytes = 0;

        do {
            $batch = $this->mediaObjectRepository->findEligibleForDiskPurge($cutoff, $includeDms, self::BATCH_SIZE);
            foreach ($batch as $attachment) {
                $bytes += $attachment->size ?? 0;
                $this->mediaObjectService->purgeAttachment($attachment);
                ++$files;
            }
            $this->flush();
            $freePercent = $this->freePercent($total);
        } while ([] !== $batch && $freePercent < $target);

        $reachedTarget = $freePercent >= $target;
        $freePercent = round($freePercent, 1);

        $this->auditLogger->record(
            $reachedTarget ? AdminAuditAction::DiskPressurePurge : AdminAuditAction::DiskPressurePurgeExhausted,
            'server_config',
            null,
            ['files' => $files, 'bytes' => $bytes, 'freePercent' => $freePercent],
        );
        $this->notifyAdmins($reachedTarget, $files, $bytes, $freePercent);

        return ['files' => $files, 'bytes' => $bytes, 'freePercent' => $freePercent, 'reachedTarget' => $reachedTarget];
    }

    private function freePercent(int $total): float
    {
        return $this->diskSpaceProbe->freeBytes() / $total * 100;
    }

    private function notifyAdmins(bool $reachedTarget, int $files, int $bytes, float $freePercent): void
    {
        $reason = $reachedTarget
            ? sprintf(
                'Low disk space: deleted %d attachment(s), freed %.1f MB. Free space is now %.1f%%.',
                $files,
                $bytes / 1_048_576,
                $freePercent,
            )
            : sprintf(
                'Low disk space: deleted %d eligible attachment(s) but free space is still %.1f%% — protected files were not touched. Consider adding storage.',
                $files,
                $freePercent,
            );

        foreach ($this->userService->getAdmins() as $admin) {
            $this->notificationService->new(new CreateNotificationDto(
                recipient: $admin,
                type: NotificationType::DiskPressurePurge,
                reason: $reason,
            ));
        }
    }
}
