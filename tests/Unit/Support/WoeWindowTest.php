<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Rathena\WoeWindow;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * War of Emperium windows.
 *
 * Two of these cover defects in the legacy implementation, which built
 * timestamps with strtotime('Sunday 12:00') and compared them against a Unix
 * timestamp: a window whose end day preceded its start day never matched, and
 * the per-pair timezone was ignored because a Unix timestamp has no timezone.
 * See docs/MIGRATION_DECISIONS.md (D13).
 */
final class WoeWindowTest extends TestCase
{
    private function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function window(array $config): WoeWindow
    {
        return WoeWindow::fromConfig($config);
    }

    #[Test]
    public function it_matches_a_moment_inside_a_same_day_window(): void
    {
        // Sunday 12:00 to 14:00.
        $window = $this->window(['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);

        // 2026-10-04 is a Sunday.
        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-10-04 13:00', 'UTC'), $this->utc()));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-04 11:59', 'UTC'), $this->utc()));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-04 15:00', 'UTC'), $this->utc()));
    }

    #[Test]
    public function the_window_includes_its_start_and_excludes_its_end(): void
    {
        $window = $this->window(['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);

        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-10-04 12:00', 'UTC'), $this->utc()));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-04 14:00', 'UTC'), $this->utc()));
    }

    #[Test]
    public function it_does_not_match_the_same_time_on_a_different_day(): void
    {
        $window = $this->window(['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);

        // 2026-10-05 is the Monday after.
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-05 13:00', 'UTC'), $this->utc()));
    }

    #[Test]
    public function it_matches_a_window_that_wraps_across_the_end_of_the_week(): void
    {
        // Saturday 23:00 to Sunday 01:00. The legacy implementation produced
        // an end earlier than its start for this and never matched at all.
        $window = $this->window(['day' => 6, 'start' => '23:00', 'end_day' => 0, 'end' => '01:00']);

        // 2026-10-03 is a Saturday, 2026-10-04 the Sunday following.
        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-10-03 23:30', 'UTC'), $this->utc()));
        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-10-04 00:30', 'UTC'), $this->utc()));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-04 01:30', 'UTC'), $this->utc()));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-03 22:30', 'UTC'), $this->utc()));
    }

    #[Test]
    public function it_reports_the_duration_of_a_wrapping_window(): void
    {
        $window = $this->window(['day' => 6, 'start' => '23:00', 'end_day' => 0, 'end' => '01:00']);

        $this->assertSame(120, $window->durationInMinutes());
    }

    #[Test]
    public function it_evaluates_the_window_in_the_servers_timezone(): void
    {
        // Sunday 12:00-14:00 as the server reckons it. The server runs in
        // Tokyo; the moment below is 04:00 UTC, which is 13:00 in Tokyo and so
        // inside the window. Comparing in UTC would wrongly say no.
        $window = $this->window(['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);
        $tokyo = new DateTimeZone('Asia/Tokyo');

        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-10-04 04:00', 'UTC'), $tokyo));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-10-04 04:00', 'UTC'), $this->utc()));
    }

    #[Test]
    public function it_finds_the_next_start_after_a_moment(): void
    {
        $window = $this->window(['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);

        // From Monday, the next Sunday 12:00 is six days later.
        $next = $window->nextStart(CarbonImmutable::parse('2026-10-05 09:00', 'UTC'), $this->utc());

        $this->assertSame('2026-10-11 12:00', $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function it_rolls_the_next_start_into_the_following_week_once_this_weeks_has_passed(): void
    {
        $window = $this->window(['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);

        $next = $window->nextStart(CarbonImmutable::parse('2026-10-04 13:00', 'UTC'), $this->utc());

        $this->assertSame('2026-10-11 12:00', $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function it_rejects_a_day_outside_the_week(): void
    {
        // The legacy panel silently discarded malformed entries, so a typo
        // turned WoE restrictions off without telling anyone.
        $this->expectException(InvalidArgumentException::class);

        $this->window(['day' => 7, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00']);
    }

    #[Test]
    public function it_rejects_a_malformed_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->window(['day' => 0, 'start' => 'noon', 'end_day' => 0, 'end' => '14:00']);
    }

    #[Test]
    public function it_rejects_an_impossible_time_of_day(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->window(['day' => 0, 'start' => '25:00', 'end_day' => 0, 'end' => '26:00']);
    }
}
