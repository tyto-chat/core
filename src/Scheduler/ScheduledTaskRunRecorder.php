<?php

declare(strict_types=1);

namespace App\Scheduler;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onHandled')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onFailed')]
final class ScheduledTaskRunRecorder
{
    private const CACHE_KEY = 'scheduler.task_runs';

    /** @var array<class-string, true>|null */
    private ?array $known = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ScheduledTaskRegistry $registry,
    ) {
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->record($event->getEnvelope()->getMessage(), 'ok');
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->record($event->getEnvelope()->getMessage(), 'failed');
    }

    private function record(object $message, string $status): void
    {
        $class = $message::class;
        if (!isset($this->known()[$class])) {
            return;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        /** @var array<class-string, array{at: int, status: string}> $runs */
        $runs = $item->isHit() ? (array) $item->get() : [];
        $runs[$class] = ['at' => time(), 'status' => $status];
        $item->set($runs);
        $this->cache->save($item);
    }

    /** @return array<class-string, array{at: int, status: string}> */
    public function lastRuns(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        /* @var array<class-string, array{at: int, status: string}> */
        return $item->isHit() ? (array) $item->get() : [];
    }

    /** @return array<class-string, true> */
    private function known(): array
    {
        if (null !== $this->known) {
            return $this->known;
        }

        $known = [];
        foreach ($this->registry->tasks() as $task) {
            $known[$task['class']] = true;
        }

        return $this->known = $known;
    }
}
