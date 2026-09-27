<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Core\Exceptions\CoreException;
use DateTimeInterface;

/**
 * @file CronExpression.php
 * @brief Minimal five-field cron expression matcher.
 */

/**
 * @class CronExpression
 * @brief Parses "minute hour day-of-month month day-of-week" and tells whether a minute matches.
 *
 * Supported syntax per field: `*`, `5`, `1-5`, `1,15,30`, `*\/10`, `0-30/5`; weekday 0 or 7 = Sunday.
 * Macros: @hourly, @daily, @weekly, @monthly, @yearly. As in standard cron, when both day-of-month
 * and day-of-week are restricted, a day matching either of them matches.
 */
final class CronExpression
{
    /**
     * @var array<string, string> Supported macros.
     */
    private const MACROS = [
        '@hourly'  => '0 * * * *',
        '@daily'   => '0 0 * * *',
        '@weekly'  => '0 0 * * 0',
        '@monthly' => '0 0 1 * *',
        '@yearly'  => '0 0 1 1 *',
    ];

    /**
     * @var list<array{0: int, 1: int}> Allowed ranges of the five fields.
     */
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /**
     * @var list<array<int, true>> Allowed values per field.
     */
    private array $fields = [];

    /**
     * @var array{0: bool, 1: bool} Whether day-of-month / day-of-week are restricted (not '*').
     */
    private array $restricted;

    /**
     * @brief CronExpression constructor.
     *
     * @param string $expression Cron expression.
     * @throws CoreException On invalid syntax.
     */
    public function __construct(private readonly string $expression)
    {
        $normalized = self::MACROS[strtolower(trim($expression))] ?? trim($expression);
        $parts = preg_split('/\s+/', $normalized) ?: [];

        if (count($parts) !== 5) {
            throw new CoreException("Invalid cron expression '{$expression}': expected 5 fields.");
        }

        foreach ($parts as $index => $part) {
            $this->fields[] = $this->parseField($part, self::RANGES[$index][0], self::RANGES[$index][1], $expression);
        }

        // Sunday may be written as 0 or 7
        if (isset($this->fields[4][7])) {
            $this->fields[4][0] = true;
        }

        $this->restricted = [$parts[2] !== '*', $parts[4] !== '*'];
    }

    /**
     * @brief Checks whether the expression matches the minute of the given time.
     *
     * @param DateTimeInterface $time Time to test.
     * @return bool
     */
    public function isDue(DateTimeInterface $time): bool
    {
        $minute = (int)$time->format('i');
        $hour = (int)$time->format('G');
        $day = (int)$time->format('j');
        $month = (int)$time->format('n');
        $weekday = (int)$time->format('w');

        if (!isset($this->fields[0][$minute], $this->fields[1][$hour], $this->fields[3][$month])) {
            return false;
        }

        $dayMatches = isset($this->fields[2][$day]);
        $weekdayMatches = isset($this->fields[4][$weekday]);

        return $this->restricted[0] && $this->restricted[1]
            ? $dayMatches || $weekdayMatches
            : $dayMatches && $weekdayMatches;
    }

    /**
     * @brief Returns the original expression.
     *
     * @return string
     */
    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * @brief Expands one field into the set of allowed values.
     *
     * @param string $field Field text.
     * @param int $min Lowest allowed value.
     * @param int $max Highest allowed value.
     * @param string $expression Whole expression (for error messages).
     * @return array<int, true>
     * @throws CoreException On invalid syntax or out-of-range values.
     */
    private function parseField(string $field, int $min, int $max, string $expression): array
    {
        $values = [];

        foreach (explode(',', $field) as $item) {
            if (!preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/D', $item, $matches)) {
                throw new CoreException("Invalid cron expression '{$expression}': cannot parse '{$item}'.");
            }

            [$from, $to] = $matches[1] === '*'
                ? [$min, $max]
                : (str_contains($matches[1], '-') ? array_map('intval', explode('-', $matches[1])) : [(int)$matches[1], (int)$matches[1]]);
            $step = isset($matches[2]) ? (int)$matches[2] : 1;

            if ($from < $min || $to > $max || $from > $to || $step < 1) {
                throw new CoreException("Invalid cron expression '{$expression}': '{$item}' is out of range {$min}-{$max}.");
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }
}
