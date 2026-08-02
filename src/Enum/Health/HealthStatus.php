<?php

declare(strict_types=1);

namespace App\Enum\Health;

enum HealthStatus: string
{
    case Down = 'down';
    case Degraded = 'degraded';
    case Unknown = 'unknown';
    case Ok = 'ok';

    /**
     * @param list<self> $statuses
     */
    public static function worstOf(array $statuses): self
    {
        if ([] === $statuses) {
            return self::Ok;
        }

        $worst = self::Ok;
        $rank = [
            self::Down->value => 0,
            self::Degraded->value => 1,
            self::Unknown->value => 2,
            self::Ok->value => 3,
        ];
        foreach ($statuses as $s) {
            if ($rank[$s->value] < $rank[$worst->value]) {
                $worst = $s;
            }
        }

        return $worst;
    }
}
