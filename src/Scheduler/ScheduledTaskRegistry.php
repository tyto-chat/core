<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Schedule;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class ScheduledTaskRegistry
{
    /** @var list<array{class: class-string, name: string, description: string, trigger: TriggerInterface}>|null */
    private ?array $tasks = null;

    public function __construct(private readonly Schedule $schedule)
    {
    }

    /** @return list<array{class: class-string, name: string, description: string, trigger: TriggerInterface}> */
    public function tasks(): array
    {
        if (null !== $this->tasks) {
            return $this->tasks;
        }

        $tasks = [];
        foreach ($this->schedule->getSchedule()->getRecurringMessages() as $recurring) {
            $message = $this->firstMessage($recurring);
            if (null === $message) {
                continue;
            }
            $class = $message::class;
            $tasks[] = [
                'class' => $class,
                'name' => $this->shortName($class),
                'description' => (string) $recurring->getTrigger(),
                'trigger' => $recurring->getTrigger(),
            ];
        }

        return $this->tasks = $tasks;
    }

    private function firstMessage(RecurringMessage $recurring): ?object
    {
        $context = new MessageContext('', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
        foreach ($recurring->getMessages($context) as $message) {
            return $message;
        }

        return null;
    }

    /** @param class-string $class */
    private function shortName(string $class): string
    {
        $short = (new \ReflectionClass($class))->getShortName();

        return preg_replace('/Message$/', '', $short) ?? $short;
    }
}
