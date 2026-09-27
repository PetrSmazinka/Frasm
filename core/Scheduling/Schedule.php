<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Attribute;
use Core\Exceptions\CoreException;

/**
 * @file Schedule.php
 * @brief Attribute running a controller action (or task method) periodically.
 */

/**
 * @class Schedule
 * @brief Declares how often `php bin/frasm schedule:run` (cron, every minute) executes a method.
 *
 * On a routed controller action the same method stays callable through its endpoint; both ways
 * share one lock, so a manual run and a scheduled run never overlap, and a manual run also resets
 * the interval.
 *
 * @code
 * #[Post('/reports/sync')]
 * #[Schedule(everyMinutes: 15)]
 * public function sync(): Response { ... }
 *
 * #[Schedule(cron: '0 7 * * 1-5')]   // weekdays at 07:00
 * public function morningReport(): void { ... }
 * @endcode
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Schedule
{
    /**
     * @brief Schedule constructor.
     *
     * @param int|null $everyMinutes Interval in minutes since the last start (manual or scheduled).
     * @param string|null $cron Five-field cron expression (minute hour day month weekday) or @hourly/@daily/...
     * @param int $timeout Seconds after which the lock of a crashed run expires.
     * @throws CoreException Unless exactly one of $everyMinutes and $cron is given.
     */
    public function __construct(
        public readonly ?int $everyMinutes = null,
        public readonly ?string $cron = null,
        public readonly int $timeout = 3600
    ) {
        if (($everyMinutes === null) === ($cron === null)) {
            throw new CoreException('#[Schedule] needs exactly one of everyMinutes or cron.');
        }
        if ($everyMinutes !== null && $everyMinutes < 1) {
            throw new CoreException('#[Schedule] everyMinutes must be at least 1.');
        }
        if ($cron !== null) {
            new CronExpression($cron);
        }
        if ($timeout < 1) {
            throw new CoreException('#[Schedule] timeout must be positive.');
        }
    }

    /**
     * @brief Returns a human readable description of the schedule.
     *
     * @return string
     */
    public function describe(): string
    {
        return $this->everyMinutes !== null ? "every {$this->everyMinutes} min" : "cron {$this->cron}";
    }
}
