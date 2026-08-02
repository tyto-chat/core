<?php

declare(strict_types=1);

namespace App\Scheduler;

final class ScheduledTasksProvider
{
    public function __construct(
        private readonly ScheduledTaskRegistry $registry,
        private readonly ScheduledTaskRunRecorder $recorder,
    ) {
    }

    /**
     * @return list<array{name: string, description: string, nextRunAt: ?string, lastRunAt: ?string, lastStatus: ?string}>
     */
    public function list(): array
    {
        $now = new \DateTimeImmutable();
        $runs = $this->recorder->lastRuns();

        $tasks = [];
        foreach ($this->registry->tasks() as $task) {
            $next = $task['trigger']->getNextRunDate($now);
            $run = $runs[$task['class']] ?? null;
            $tasks[] = [
                'name' => $task['name'],
                'description' => $task['description'],
                'nextRunAt' => $next?->format(\DateTimeInterface::ATOM),
                'lastRunAt' => isset($run['at'])
                    ? (new \DateTimeImmutable('@'.$run['at']))->format(\DateTimeInterface::ATOM)
                    : null,
                'lastStatus' => $run['status'] ?? null,
            ];
        }

        usort($tasks, static fn (array $a, array $b): int => ($a['nextRunAt'] ?? '~') <=> ($b['nextRunAt'] ?? '~'));

        return $tasks;
    }
}
