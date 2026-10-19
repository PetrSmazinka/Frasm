<?php

declare(strict_types=1);

namespace Core\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * @file IcsReader.php
 * @brief Reads iCalendar (RFC 5545) files: the events of a period with their repetitions.
 */

/**
 * @class IcsReader
 * @brief Turns the VEVENTs of an .ics file into the times they take within a period.
 *
 * Supported: all-day, UTC, floating and TZID times (IANA names and the common Windows names of Outlook), DTEND or
 * DURATION, RRULE (FREQ DAILY/WEEKLY/MONTHLY/YEARLY, INTERVAL, COUNT, UNTIL, BYDAY incl. ordinals as 1MO or -1FR,
 * BYMONTHDAY, BYMONTH), EXDATE, changed or cancelled repetitions (RECURRENCE-ID), STATUS:CANCELLED and TRANSP. Rules
 * beyond that (BYSETPOS, BYWEEKNO, hourly …) fall back to their first time. Times come in the given time zone.
 *
 * ```php
 * foreach ((new IcsReader())->occurrences($ics, $from, $to) as $event) { … $event['start'], $event['end'] … }
 * ```
 */
class IcsReader
{
    /**
     * @var int Most periods of one rule examined (a guard against endless rules).
     */
    private const MAX_PERIODS = 5000;

    /**
     * @var array<string, string> Windows time zone names (Outlook) → IANA.
     */
    private const WINDOWS_ZONES = [
        'Central Europe Standard Time'   => 'Europe/Prague',
        'Central European Standard Time' => 'Europe/Warsaw',
        'W. Europe Standard Time'        => 'Europe/Berlin',
        'Romance Standard Time'          => 'Europe/Paris',
        'GMT Standard Time'              => 'Europe/London',
        'E. Europe Standard Time'        => 'Europe/Chisinau',
        'FLE Standard Time'              => 'Europe/Kiev',
        'Russian Standard Time'          => 'Europe/Moscow',
        'Eastern Standard Time'          => 'America/New_York',
        'Pacific Standard Time'          => 'America/Los_Angeles',
        'UTC'                            => 'UTC',
        'Coordinated Universal Time'     => 'UTC',
    ];

    /**
     * @var array<string, int> Weekday codes → ISO numbers.
     */
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * @brief Times of the events of a file overlapping a period, by start.
     *
     * @param string $ics Content of the file.
     * @param DateTimeImmutable $from Start of the period.
     * @param DateTimeImmutable $to End of the period (exclusive).
     * @param DateTimeZone|null $zone Zone of the results and of floating times (default: of $from).
     * @return list<array{uid: string, summary: string, start: DateTimeImmutable, end: DateTimeImmutable, all_day: bool, transparent: bool}>
     *         End exclusive (an all-day event ends at 00:00 after its last day).
     */
    public function occurrences(string $ics, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeZone $zone = null): array
    {
        $zone ??= $from->getTimezone();
        $masters = [];
        $overrides = [];
        foreach ($this->events($ics) as $event) {
            if (!isset($event['DTSTART'])) {
                continue;
            }
            $uid = (string)($event['UID'][0]['value'] ?? '');
            if (isset($event['RECURRENCE-ID'])) {
                $original = $this->time($event['RECURRENCE-ID'][0], $zone);
                if ($original !== null) {
                    $overrides[$uid][$original[0]->getTimestamp()] = $event;
                }
                continue;
            }
            $masters[] = $event;
        }

        $result = [];
        foreach ($masters as $event) {
            $uid = (string)($event['UID'][0]['value'] ?? '');
            foreach ($this->expand($event, $zone, $to) as $start) {
                $key = $start->getTimestamp();
                $instance = $event;
                if (isset($overrides[$uid][$key])) {
                    $instance = $overrides[$uid][$key];
                    unset($overrides[$uid][$key]);
                    $own = $this->time($instance['DTSTART'][0], $zone);
                    $start = $own[0] ?? $start;
                }
                $this->add($result, $instance, $start, $zone, $from, $to);
            }
        }
        // Repetitions moved to a time outside the rule (or of a master without the rule)
        foreach ($overrides as $moved) {
            foreach ($moved as $event) {
                $own = $this->time($event['DTSTART'][0], $zone);
                if ($own !== null) {
                    $this->add($result, $event, $own[0], $zone, $from, $to);
                }
            }
        }
        usort($result, fn(array $a, array $b): int => [$a['start'], $a['uid']] <=> [$b['start'], $b['uid']]);

        return $result;
    }

    /**
     * @brief Adds one time of an event when it takes place and overlaps the period.
     *
     * @param list<array<string, mixed>> $result Times.
     * @param array<string, list<array{value: string, params: array<string, string>}>> $event Event.
     * @param DateTimeImmutable $start Its start.
     * @param DateTimeZone $zone Zone of the results.
     * @param DateTimeImmutable $from Start of the period.
     * @param DateTimeImmutable $to End of the period.
     * @return void
     */
    private function add(array &$result, array $event, DateTimeImmutable $start, DateTimeZone $zone, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        if (strtoupper((string)($event['STATUS'][0]['value'] ?? '')) === 'CANCELLED') {
            return;
        }
        [, $allDay] = $this->time($event['DTSTART'][0], $zone) ?? [null, false];
        $end = $start->add($this->length($event, $zone));
        if ($end <= $start) {
            $end = $allDay ? $start->modify('+1 day') : $start;
        }
        // Overlap with the period; an event without length counts at its start
        if ($start >= $to || ($end > $start ? $end <= $from : $start < $from)) {
            return;
        }
        $result[] = [
            'uid'         => (string)($event['UID'][0]['value'] ?? ''),
            'summary'     => self::text((string)($event['SUMMARY'][0]['value'] ?? '')),
            'start'       => $start->setTimezone($zone),
            'end'         => $end->setTimezone($zone),
            'all_day'     => $allDay,
            'transparent' => strtoupper((string)($event['TRANSP'][0]['value'] ?? 'OPAQUE')) === 'TRANSPARENT',
        ];
    }

    /**
     * @brief Length of an event (DTEND − DTSTART, or DURATION; none: all-day one day, else zero).
     *
     * @param array<string, list<array{value: string, params: array<string, string>}>> $event Event.
     * @param DateTimeZone $zone Zone of floating times.
     * @return DateInterval
     */
    private function length(array $event, DateTimeZone $zone): DateInterval
    {
        $start = $this->time($event['DTSTART'][0], $zone);
        if ($start === null) {
            return new DateInterval('PT0S');
        }
        if (isset($event['DTEND'])) {
            $end = $this->time($event['DTEND'][0], $zone);
            if ($end !== null && $end[0] > $start[0]) {
                // All-day lengths in days (DST does not shift them), others in seconds
                return $start[1] ? new DateInterval('P' . (int)$start[0]->diff($end[0])->format('%a') . 'D')
                    : new DateInterval('PT' . ($end[0]->getTimestamp() - $start[0]->getTimestamp()) . 'S');
            }
        }
        if (isset($event['DURATION'])) {
            try {
                $duration = ltrim(strtoupper(trim($event['DURATION'][0]['value'])), '+');
                return new DateInterval(str_starts_with($duration, '-') ? 'PT0S' : $duration);
            } catch (Throwable) {
            }
        }

        return new DateInterval($start[1] ? 'P1D' : 'PT0S');
    }

    /**
     * @brief Starts of an event and of its repetitions up to an end (EXDATE removed), at most MAX_PERIODS periods.
     *
     * @param array<string, list<array{value: string, params: array<string, string>}>> $event Event.
     * @param DateTimeZone $zone Zone of floating times.
     * @param DateTimeImmutable $until Last start considered (exclusive).
     * @return list<DateTimeImmutable>
     */
    private function expand(array $event, DateTimeZone $zone, DateTimeImmutable $until): array
    {
        $first = $this->time($event['DTSTART'][0], $zone);
        if ($first === null) {
            return [];
        }
        [$start, $allDay] = $first;
        $excluded = [];
        foreach ($event['EXDATE'] ?? [] as $exdate) {
            foreach (explode(',', $exdate['value']) as $value) {
                $time = $this->time(['value' => trim($value), 'params' => $exdate['params']], $zone);
                if ($time !== null) {
                    $excluded[$allDay ? $time[0]->format('Y-m-d') : (string)$time[0]->getTimestamp()] = true;
                }
            }
        }
        $isExcluded = fn(DateTimeImmutable $time): bool => isset($excluded[$allDay ? $time->format('Y-m-d') : (string)$time->getTimestamp()]);

        $rule = isset($event['RRULE']) ? self::rule($event['RRULE'][0]['value']) : null;
        if ($rule === null || !in_array($rule['FREQ'] ?? '', ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            return $isExcluded($start) ? [] : [$start];
        }

        $interval = max(1, (int)($rule['INTERVAL'] ?? 1));
        $count = isset($rule['COUNT']) ? max(1, (int)$rule['COUNT']) : null;
        $ruleUntil = isset($rule['UNTIL']) ? $this->time(['value' => $rule['UNTIL'], 'params' => []], $start->getTimezone()) : null;
        $last = $ruleUntil !== null ? ($ruleUntil[1] ? $ruleUntil[0]->setTime(23, 59, 59) : $ruleUntil[0]) : null;
        $byDay = array_values(array_filter(array_map('trim', explode(',', (string)($rule['BYDAY'] ?? ''))), fn(string $day): bool => $day !== ''));
        $byMonthDay = array_values(array_filter(array_map('intval', explode(',', (string)($rule['BYMONTHDAY'] ?? ''))), fn(int $day): bool => $day !== 0));
        $byMonth = array_values(array_filter(array_map('intval', explode(',', (string)($rule['BYMONTH'] ?? ''))), fn(int $month): bool => $month >= 1 && $month <= 12));

        $starts = [];
        $emitted = 0;
        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            $candidates = match ($rule['FREQ']) {
                'DAILY'   => $this->daily($start, $period * $interval, $byDay, $byMonth),
                'WEEKLY'  => $this->weekly($start, $period * $interval, $byDay),
                'MONTHLY' => $this->monthly($start, $period * $interval, $byDay, $byMonthDay, $byMonth),
                'YEARLY'  => $this->yearly($start, $period * $interval, $byDay, $byMonthDay, $byMonth),
            };
            sort($candidates);
            foreach ($candidates as $candidate) {
                if ($candidate < $start) {
                    continue;
                }
                if (($last !== null && $candidate > $last) || $candidate >= $until) {
                    return $starts;
                }
                $emitted++;
                if (!$isExcluded($candidate)) {
                    $starts[] = $candidate;
                }
                if ($count !== null && $emitted >= $count) {
                    return $starts;
                }
            }
        }

        return $starts;
    }

    /**
     * @brief Candidates of a day period.
     *
     * @param DateTimeImmutable $start First start.
     * @param int $days Days after it.
     * @param list<string> $byDay BYDAY filter.
     * @param list<int> $byMonth BYMONTH filter.
     * @return list<DateTimeImmutable>
     */
    private function daily(DateTimeImmutable $start, int $days, array $byDay, array $byMonth): array
    {
        $day = $start->modify("+{$days} days");
        $weekdays = array_map(fn(string $code): ?int => self::DAYS[substr($code, -2)] ?? null, $byDay);
        if (($weekdays !== [] && !in_array((int)$day->format('N'), $weekdays, true)) || ($byMonth !== [] && !in_array((int)$day->format('n'), $byMonth, true))) {
            return [];
        }

        return [$day];
    }

    /**
     * @brief Candidates of a week period (the week of the start, Monday first, moved by weeks).
     *
     * @param DateTimeImmutable $start First start.
     * @param int $weeks Weeks after it.
     * @param list<string> $byDay Weekdays (MO, TU, …); none = the weekday of the start.
     * @return list<DateTimeImmutable>
     */
    private function weekly(DateTimeImmutable $start, int $weeks, array $byDay): array
    {
        $monday = $start->modify('-' . ((int)$start->format('N') - 1) . ' days')->modify('+' . (7 * $weeks) . ' days');
        if ($byDay === []) {
            return [$start->modify('+' . (7 * $weeks) . ' days')];
        }
        $days = [];
        foreach ($byDay as $code) {
            $number = self::DAYS[substr($code, -2)] ?? null;
            if ($number !== null) {
                $days[] = $monday->modify('+' . ($number - 1) . ' days');
            }
        }

        return $days;
    }

    /**
     * @brief Candidates of a month period.
     *
     * @param DateTimeImmutable $start First start.
     * @param int $months Months after it.
     * @param list<string> $byDay Weekdays, optionally with an ordinal (1MO, -1FR).
     * @param list<int> $byMonthDay Days of the month (negative from its end).
     * @param list<int> $byMonth BYMONTH filter.
     * @return list<DateTimeImmutable>
     */
    private function monthly(DateTimeImmutable $start, int $months, array $byDay, array $byMonthDay, array $byMonth): array
    {
        $month = $start->modify('first day of this month')->modify("+{$months} months");
        if ($byMonth !== [] && !in_array((int)$month->format('n'), $byMonth, true)) {
            return [];
        }

        return $this->inMonth($start, $month, $byDay, $byMonthDay);
    }

    /**
     * @brief Candidates of a year period.
     *
     * @param DateTimeImmutable $start First start.
     * @param int $years Years after it.
     * @param list<string> $byDay Weekdays, optionally with an ordinal.
     * @param list<int> $byMonthDay Days of the month.
     * @param list<int> $byMonth Months (none = the month of the start).
     * @return list<DateTimeImmutable>
     */
    private function yearly(DateTimeImmutable $start, int $years, array $byDay, array $byMonthDay, array $byMonth): array
    {
        $year = (int)$start->format('Y') + $years;
        $days = [];
        foreach ($byMonth !== [] ? $byMonth : [(int)$start->format('n')] as $number) {
            $month = $start->setDate($year, $number, 1);
            array_push($days, ...$this->inMonth($start, $month, $byDay, $byMonthDay));
        }

        return $days;
    }

    /**
     * @brief Days of one month by BYDAY / BYMONTHDAY (none: the day of the month of the start, if the month has it).
     *
     * @param DateTimeImmutable $start First start (gives the time of day).
     * @param DateTimeImmutable $month First day of the month.
     * @param list<string> $byDay Weekdays, optionally with an ordinal.
     * @param list<int> $byMonthDay Days of the month.
     * @return list<DateTimeImmutable>
     */
    private function inMonth(DateTimeImmutable $start, DateTimeImmutable $month, array $byDay, array $byMonthDay): array
    {
        $length = (int)$month->format('t');
        $at = fn(int $day): DateTimeImmutable => $start->setDate((int)$month->format('Y'), (int)$month->format('n'), $day);
        $days = [];
        foreach ($byMonthDay as $day) {
            $day = $day < 0 ? $length + $day + 1 : $day;
            if ($day >= 1 && $day <= $length) {
                $days[] = $at($day);
            }
        }
        foreach ($byDay as $code) {
            if (!preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/D', $code, $match)) {
                continue;
            }
            $weekday = self::DAYS[$match[2]];
            $matching = [];
            for ($day = 1; $day <= $length; $day++) {
                if ((int)$at($day)->format('N') === $weekday) {
                    $matching[] = $day;
                }
            }
            $ordinal = (int)($match[1] ?? 0);
            if ($ordinal === 0) {
                $picked = $matching;
            } else {
                $index = $ordinal > 0 ? $ordinal - 1 : count($matching) + $ordinal;
                $picked = isset($matching[$index]) ? [$matching[$index]] : [];
            }
            // With BYMONTHDAY as well, its days are kept when their weekday matches (below)
            if ($byMonthDay === []) {
                foreach ($picked as $day) {
                    $days[] = $at($day);
                }
            }
        }
        if ($byMonthDay !== [] && $byDay !== []) {
            $weekdays = array_map(fn(string $code): ?int => self::DAYS[substr($code, -2)] ?? null, $byDay);
            $days = array_values(array_filter($days, fn(DateTimeImmutable $day): bool => in_array((int)$day->format('N'), $weekdays, true)));
        }
        if ($byDay === [] && $byMonthDay === []) {
            $day = (int)$start->format('j');
            if ($day <= $length) {
                $days[] = $at($day);
            }
        }

        return $days;
    }

    /**
     * @brief Reads a date or date-time property.
     *
     * @param array{value: string, params: array<string, string>} $property Property.
     * @param DateTimeZone $zone Zone of floating times.
     * @return array{0: DateTimeImmutable, 1: bool}|null Time and whether it is a date only; null when unreadable.
     */
    private function time(array $property, DateTimeZone $zone): ?array
    {
        $value = trim($property['value']);
        try {
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/D', $value, $match)) {
                return [new DateTimeImmutable("{$match[1]}-{$match[2]}-{$match[3]} 00:00:00", $zone), true];
            }
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/D', $value, $match)) {
                return null;
            }
            $text = "{$match[1]}-{$match[2]}-{$match[3]} {$match[4]}:{$match[5]}:{$match[6]}";
            if ($match[7] === 'Z') {
                return [(new DateTimeImmutable($text, new DateTimeZone('UTC')))->setTimezone($zone), false];
            }

            return [new DateTimeImmutable($text, self::zone($property['params']['TZID'] ?? null) ?? $zone), false];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @brief Zone of a TZID (IANA or a Windows name).
     *
     * @param string|null $tzid TZID parameter.
     * @return DateTimeZone|null Null when unknown.
     */
    private static function zone(?string $tzid): ?DateTimeZone
    {
        if ($tzid === null || $tzid === '') {
            return null;
        }
        $tzid = trim($tzid, '"/ ');
        try {
            return new DateTimeZone(self::WINDOWS_ZONES[$tzid] ?? $tzid);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @brief Parts of an RRULE.
     *
     * @param string $value Value (FREQ=WEEKLY;BYDAY=MO,WE;…).
     * @return array<string, string>
     */
    private static function rule(string $value): array
    {
        $rule = [];
        foreach (explode(';', strtoupper($value)) as $part) {
            [$name, $item] = array_pad(explode('=', $part, 2), 2, '');
            if ($name !== '') {
                $rule[trim($name)] = trim($item);
            }
        }

        return $rule;
    }

    /**
     * @brief VEVENTs of a file: property name => list of {value, params} (lines unfolded).
     *
     * @param string $ics Content.
     * @return list<array<string, list<array{value: string, params: array<string, string>}>>>
     */
    private function events(string $ics): array
    {
        // Unfold: a line break followed by a space or tab continues the line
        $ics = (string)preg_replace("/\n[ \t]/", '', str_replace(["\r\n", "\r"], "\n", $ics));
        $events = [];
        $current = null;
        $depth = 0;
        foreach (explode("\n", $ics) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT') {
                $current = [];
                $depth = 0;
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (str_starts_with($upper, 'BEGIN:')) {
                $depth++; // VALARM and the like
                continue;
            }
            if ($upper === 'END:VEVENT') {
                $events[] = $current;
                $current = null;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            $property = self::property($line);
            if ($property !== null) {
                $current[$property[0]][] = ['value' => $property[1], 'params' => $property[2]];
            }
        }

        return $events;
    }

    /**
     * @brief Splits a content line: NAME;PARAM=value;…:value.
     *
     * @param string $line Unfolded line.
     * @return array{0: string, 1: string, 2: array<string, string>}|null
     */
    private static function property(string $line): ?array
    {
        if (!preg_match('/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=(?:"[^"]*"|[^";:]*)(?:,(?:"[^"]*"|[^";:]*))*)*):(.*)$/sD', $line, $match)) {
            return null;
        }
        $params = [];
        if ($match[2] !== '' && preg_match_all('/;([A-Za-z0-9-]+)=("[^"]*"|[^";:]*)/', $match[2], $all, PREG_SET_ORDER)) {
            foreach ($all as $param) {
                $params[strtoupper($param[1])] = trim($param[2], '"');
            }
        }

        return [strtoupper($match[1]), $match[3], $params];
    }

    /**
     * @brief Unescapes a TEXT value.
     *
     * @param string $value Value.
     * @return string
     */
    private static function text(string $value): string
    {
        return trim(strtr($value, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\']));
    }
}
