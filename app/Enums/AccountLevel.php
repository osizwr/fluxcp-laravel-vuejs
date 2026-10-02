<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The privilege tiers the panel authorises against.
 *
 * These are not rAthena's group IDs. rAthena assigns each account a numeric
 * group_id whose meaning is defined by the server's own conf files; the panel
 * maps those IDs onto this much smaller ladder, because a control panel only
 * needs to know "is this person staff, and how senior". The mapping lives in
 * config/permissions.php under 'account_groups'.
 *
 * The integer values are FluxCP's, preserved so an operator's existing
 * configuration and expectations carry over unchanged.
 *
 * Two values are not tiers at all but sentinels:
 *
 *  - ANYONE sits below Unauthenticated so that a check against it passes for
 *    guests and members alike.
 *  - UNAUTHENTICATED means "guests only" rather than "guests and above": the
 *    login and registration pages must not be reachable once signed in.
 *  - NOONE is unreachable by design, used to disable a capability outright.
 */
enum AccountLevel: int
{
    /** Reachable by anyone, signed in or not. */
    case Anyone = -2;

    /** Reachable only while *not* signed in. */
    case Unauthenticated = -1;

    /** Any signed-in player. */
    case Player = 0;

    /** Junior staff: support, script and event managers. */
    case JuniorGameMaster = 1;

    /** Senior staff. */
    case SeniorGameMaster = 2;

    case Administrator = 99;

    /** Reachable by nobody; disables the capability. */
    case Noone = 9999;

    /**
     * The FluxCP constant this case corresponds to, for cross-referencing
     * docs/FLUXCP_FEATURE_INVENTORY.md and a legacy config/access.php.
     */
    public function legacyName(): string
    {
        return match ($this) {
            self::Anyone => 'ANYONE',
            self::Unauthenticated => 'UNAUTH',
            self::Player => 'NORMAL',
            self::JuniorGameMaster => 'LOWGM',
            self::SeniorGameMaster => 'HIGHGM',
            self::Administrator => 'ADMIN',
            self::Noone => 'NOONE',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Anyone => 'Everyone',
            self::Unauthenticated => 'Guests only',
            self::Player => 'Players',
            self::JuniorGameMaster => 'Junior Game Master',
            self::SeniorGameMaster => 'Senior Game Master',
            self::Administrator => 'Administrator',
            self::Noone => 'Nobody',
        };
    }

    /**
     * Whether this level counts as staff, i.e. anything above a player that
     * is not one of the sentinels.
     */
    public function isStaff(): bool
    {
        return match ($this) {
            self::JuniorGameMaster, self::SeniorGameMaster, self::Administrator => true,
            default => false,
        };
    }

    /**
     * Whether a viewer holding this level satisfies a requirement for
     * $required.
     *
     * This reproduces the legacy comparison in Flux_Authorization, which is
     * not a plain `>=`:
     *
     *   $accessLevel == ANYONE || $accessLevel == $accountLevel ||
     *     ($accessLevel != UNAUTH && $accessLevel <= $accountLevel)
     *
     * The three clauses matter. ANYONE always passes. An exact match always
     * passes, which is what makes UNAUTH mean "guests only" -- a signed-in
     * player does not satisfy it, even though 0 > -1. Otherwise it is a
     * normal "at least this senior" comparison, and because NOONE is above
     * every real level, nothing satisfies it.
     */
    public function satisfies(self $required): bool
    {
        if ($required === self::Anyone) {
            return true;
        }

        if ($required === $this) {
            return true;
        }

        if ($required === self::Unauthenticated) {
            return false;
        }

        return $required->value <= $this->value;
    }
}
