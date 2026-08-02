<?php

declare(strict_types=1);

namespace App;

use App\Async\DiskPressurePurgeMessage;
use App\Async\ExpireDataExportsMessage;
use App\Async\PruneWebhookDeliveriesMessage;
use App\Async\PurgeArchivedChannelsMessage;
use App\Async\PurgeExpiredAccountsMessage;
use App\Async\PurgeRetentionMessage;
use App\Async\ReconcileAudioParticipantsMessage;
use App\Async\SamplePresenceMessage;
use App\Async\SendEmailDigestsMessage;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        // Read at schedule-build time; a change takes effect on the next scheduler-worker cycle (<=1h via --time-limit=3600).
        $hour = $this->settings->get(Settings::digestHour());

        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                // Fallback only — voice-roster sync is event-driven via the LiveKit webhook; this catches lost events.
                RecurringMessage::every('15 minutes', new ReconcileAudioParticipantsMessage()),
                RecurringMessage::every('15 minutes', new SamplePresenceMessage()),
                RecurringMessage::every(
                    '1 day',
                    new SendEmailDigestsMessage(),
                    from: new \DateTimeImmutable(sprintf('%02d:00', $hour)),
                ),
                RecurringMessage::every(
                    '1 day',
                    new PurgeExpiredAccountsMessage(),
                    from: new \DateTimeImmutable('03:00'),
                ),
                RecurringMessage::every(
                    '1 day',
                    new ExpireDataExportsMessage(),
                    from: new \DateTimeImmutable('03:30'),
                ),
                RecurringMessage::every(
                    '1 day',
                    new PruneWebhookDeliveriesMessage(),
                    from: new \DateTimeImmutable('03:45'),
                ),
                RecurringMessage::every(
                    '1 day',
                    new PurgeRetentionMessage(),
                    from: new \DateTimeImmutable('04:00'),
                ),
                RecurringMessage::every('1 day', new PurgeArchivedChannelsMessage(),
                    from: new \DateTimeImmutable('04:15')),
                RecurringMessage::every('1 day', new DiskPressurePurgeMessage(),
                    from: new \DateTimeImmutable('04:30')),
            )
        ;
    }
}
