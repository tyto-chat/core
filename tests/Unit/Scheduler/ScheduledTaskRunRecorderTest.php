<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Async\PurgeRetentionMessage;
use App\Async\ReconcileAudioParticipantsMessage;
use App\Schedule;
use App\Scheduler\ScheduledTaskRegistry;
use App\Scheduler\ScheduledTaskRunRecorder;
use App\Scheduler\ScheduledTasksProvider;
use App\Service\Settings\SettingsServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

class ScheduledTaskRunRecorderTest extends TestCase
{
    private function recorder(ArrayAdapter $pool): ScheduledTaskRunRecorder
    {
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(8);
        $registry = new ScheduledTaskRegistry(new Schedule(new ArrayAdapter(), $settings));

        return new ScheduledTaskRunRecorder($pool, $registry);
    }

    private function handled(object $message): WorkerMessageHandledEvent
    {
        return new WorkerMessageHandledEvent(Envelope::wrap($message), 'async');
    }

    public function testRecordsHandledScheduledTask(): void
    {
        $pool = new ArrayAdapter();
        $recorder = $this->recorder($pool);

        $recorder->onHandled($this->handled(new ReconcileAudioParticipantsMessage()));

        $runs = $recorder->lastRuns();
        self::assertArrayHasKey(ReconcileAudioParticipantsMessage::class, $runs);
        self::assertSame('ok', $runs[ReconcileAudioParticipantsMessage::class]['status']);
        self::assertGreaterThan(0, $runs[ReconcileAudioParticipantsMessage::class]['at']);
    }

    public function testRecordsFailedScheduledTask(): void
    {
        $pool = new ArrayAdapter();
        $recorder = $this->recorder($pool);

        $event = new WorkerMessageFailedEvent(
            Envelope::wrap(new PurgeRetentionMessage()),
            'async',
            new \RuntimeException('boom'),
        );
        $recorder->onFailed($event);

        self::assertSame('failed', $recorder->lastRuns()[PurgeRetentionMessage::class]['status']);
    }

    public function testIgnoresNonScheduledMessage(): void
    {
        $pool = new ArrayAdapter();
        $recorder = $this->recorder($pool);

        $recorder->onHandled($this->handled(new \stdClass()));

        self::assertSame([], $recorder->lastRuns());
    }

    public function testProviderMergesLastRunIntoReport(): void
    {
        $pool = new ArrayAdapter();
        $recorder = $this->recorder($pool);
        $recorder->onHandled($this->handled(new ReconcileAudioParticipantsMessage()));

        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(8);
        $registry = new ScheduledTaskRegistry(new Schedule(new ArrayAdapter(), $settings));
        $provider = new ScheduledTasksProvider($registry, $recorder);

        $report = $provider->list();
        $reconcile = null;
        foreach ($report as $task) {
            if ('ReconcileAudioParticipants' === $task['name']) {
                $reconcile = $task;
            }
        }

        self::assertNotNull($reconcile);
        self::assertSame('ok', $reconcile['lastStatus']);
        self::assertNotNull($reconcile['lastRunAt']);
        self::assertNotNull($reconcile['nextRunAt']);
    }
}
