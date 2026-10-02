<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AccountLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The privilege comparison, checked against FluxCP's.
 *
 * The legacy expression was not a plain >= and the difference is observable,
 * so each clause is pinned here:
 *
 *   $accessLevel == ANYONE || $accessLevel == $accountLevel ||
 *     ($accessLevel != UNAUTH && $accessLevel <= $accountLevel)
 */
final class AccountLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{AccountLevel, AccountLevel, bool}>
     */
    public static function comparisons(): iterable
    {
        // Anyone is satisfied by everybody, including guests.
        yield 'guest meets Anyone' => [AccountLevel::Unauthenticated, AccountLevel::Anyone, true];
        yield 'player meets Anyone' => [AccountLevel::Player, AccountLevel::Anyone, true];
        yield 'admin meets Anyone' => [AccountLevel::Administrator, AccountLevel::Anyone, true];

        // Unauthenticated means guests ONLY, not "guests and above". This is
        // what keeps a signed-in visitor off the login and registration pages,
        // and is why the comparison cannot be a simple >=: 0 > -1 would
        // otherwise let a player through.
        yield 'guest meets Unauthenticated' => [AccountLevel::Unauthenticated, AccountLevel::Unauthenticated, true];
        yield 'player does NOT meet Unauthenticated' => [AccountLevel::Player, AccountLevel::Unauthenticated, false];
        yield 'admin does NOT meet Unauthenticated' => [AccountLevel::Administrator, AccountLevel::Unauthenticated, false];

        // Ordinary seniority.
        yield 'guest does not meet Player' => [AccountLevel::Unauthenticated, AccountLevel::Player, false];
        yield 'player meets Player' => [AccountLevel::Player, AccountLevel::Player, true];
        yield 'player does not meet JuniorGM' => [AccountLevel::Player, AccountLevel::JuniorGameMaster, false];
        yield 'juniorGM meets Player' => [AccountLevel::JuniorGameMaster, AccountLevel::Player, true];
        yield 'juniorGM meets JuniorGM' => [AccountLevel::JuniorGameMaster, AccountLevel::JuniorGameMaster, true];
        yield 'juniorGM does not meet SeniorGM' => [AccountLevel::JuniorGameMaster, AccountLevel::SeniorGameMaster, false];
        yield 'seniorGM meets JuniorGM' => [AccountLevel::SeniorGameMaster, AccountLevel::JuniorGameMaster, true];
        yield 'admin meets SeniorGM' => [AccountLevel::Administrator, AccountLevel::SeniorGameMaster, true];

        // Noone is above every real level, so nothing satisfies it. This is
        // what disables the password-revealing permissions outright.
        yield 'player does not meet Noone' => [AccountLevel::Player, AccountLevel::Noone, false];
        yield 'admin does not meet Noone' => [AccountLevel::Administrator, AccountLevel::Noone, false];
        yield 'guest does not meet Noone' => [AccountLevel::Unauthenticated, AccountLevel::Noone, false];
    }

    #[Test]
    #[DataProvider('comparisons')]
    public function it_reproduces_the_legacy_privilege_comparison(
        AccountLevel $viewer,
        AccountLevel $required,
        bool $expected,
    ): void {
        $this->assertSame($expected, $viewer->satisfies($required));
    }

    #[Test]
    public function it_keeps_the_legacy_integer_values(): void
    {
        // Operators migrating a customised access.php rely on these.
        $this->assertSame(-2, AccountLevel::Anyone->value);
        $this->assertSame(-1, AccountLevel::Unauthenticated->value);
        $this->assertSame(0, AccountLevel::Player->value);
        $this->assertSame(1, AccountLevel::JuniorGameMaster->value);
        $this->assertSame(2, AccountLevel::SeniorGameMaster->value);
        $this->assertSame(99, AccountLevel::Administrator->value);
        $this->assertSame(9999, AccountLevel::Noone->value);
    }

    #[Test]
    public function it_reports_which_levels_are_staff(): void
    {
        $this->assertFalse(AccountLevel::Anyone->isStaff());
        $this->assertFalse(AccountLevel::Unauthenticated->isStaff());
        $this->assertFalse(AccountLevel::Player->isStaff());
        $this->assertTrue(AccountLevel::JuniorGameMaster->isStaff());
        $this->assertTrue(AccountLevel::SeniorGameMaster->isStaff());
        $this->assertTrue(AccountLevel::Administrator->isStaff());
        // Noone is a sentinel, not a tier, so it is not staff.
        $this->assertFalse(AccountLevel::Noone->isStaff());
    }
}
