<?php

declare(strict_types=1);

namespace App\Support\Rathena;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One War of Emperium window, expressed as a weekly recurring interval.
 *
 * Windows are stored as minutes from the start of the week so that a window
 * running from Saturday evening into Sunday morning is a single interval that
 * wraps, rather than two special cases.
 *
 * This fixes two defects in the legacy implementation, which built timestamps
 * with strtotime('Sunday 12:00') and compared them against a Unix timestamp:
 *
 *  - strtotime() resolves a day name relative to today, so a window whose end
 *    day precedes its start day produced an end earlier than its start and
 *    never matched.
 *  - the comparison used a Unix timestamp, which is timezone-independent, so
 *    the per-pair timezone was silently ignored.
 *
 * See docs/MIGRATION_DECISIONS.md (D13).
 */
final readonly class WoeWindow
{
    private const MINUTES_PER_WEEK = 7 * 24 * 60;

    private function __construct(
        public int $startDay,
        public string $startTime,
        public int $endDay,
        public string $endTime,
        private int $startMinute,
        private int $endMinute,
    ) {}

    /**
     * Build a window from configuration.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException when the window is not well formed.
     */
    public static function fromConfig(array $config): self
    {
        $startDay = (int) ($config['day'] ?? -1);
        $endDay = (int) ($config['end_day'] ?? $startDay);
        $startTime = trim((string) ($config['start'] ?? ''));
        $endTime = trim((string) ($config['end'] ?? ''));

        foreach ([$startDay, $endDay] as $day) {
            if ($day < 0 || $day > 6) {
                throw new InvalidArgumentException(
                    "War of Emperium day must be 0 (Sunday) through 6 (Saturday), got {$day}."
                );
            }
        }

        return new self(
            startDay: $startDay,
            startTime: $startTime,
            endDay: $endDay,
            endTime: $endTime,
            startMinute: self::toWeekMinute($startDay, $startTime),
            endMinute: self::toWeekMinute($endDay, $endTime),
        );
    }

    /**
     * Whether the given moment falls inside this window.
     *
     * The moment is converted into the server's own timezone first, so a
     * window declared as "Saturday 20:00" means 20:00 where the server is,
     * not where the web host happens to be.
     */
    public function contains(CarbonImmutable $moment, DateTimeZone $serverTimezone): bool
    {
        $local = $moment->setTimezone($serverTimezone);
        $minute = ((int) $local->dayOfWeek * 24 * 60) + ((int) $local->hour * 60) + (int) $local->minute;

        // A window whose end is not after its start wraps through the end of
        // the week, for example Saturday 23:00 to Sunday 01:00.
        if ($this->endMinute <= $this->startMinute) {
            return $minute >= $this->startMinute || $minute < $this->endMinute;
        }

        return $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    /**
     * The next moment this window opens, at or after the given moment.
     */
    public function nextStart(CarbonImmutable $moment, DateTimeZone $serverTimezone): CarbonImmutable
    {
        $local = $moment->setTimezone($serverTimezone);
        $weekStart = $local->startOfWeek(CarbonImmutable::SUNDAY);
        $candidate = $weekStart->addMinutes($this->startMinute);

        return $candidate->lessThanOrEqualTo($local)
            ? $candidate->addMinutes(self::MINUTES_PER_WEEK)
            : $candidate;
    }

    /**
     * Duration of the window in minutes, accounting for week wrap.
     */
    public function durationInMinutes(): int
    {
        $duration = $this->endMinute - $this->startMinute;

        return $duration > 0 ? $duration : $duration + self::MINUTES_PER_WEEK;
    }

    private static function toWeekMinute(int $day, string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches) !== 1) {
            throw new InvalidArgumentException(
                "War of Emperium time must be in HH:MM form, got '{$time}'."
            );
        }

        [, $hour, $minute] = $matches;

        if ((int) $hour > 23 || (int) $minute > 59) {
            throw new InvalidArgumentException(
                "War of Emperium time '{$time}' is not a valid time of day."
            );
        }

        return ($day * 24 * 60) + ((int) $hour * 60) + (int) $minute;
    }
}
