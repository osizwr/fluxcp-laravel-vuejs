<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of entry in a ban audit table (`cp_banlog`, `cp_ipbanlog`).
 *
 * These tables are append-only histories rather than current-state tables: a
 * lifted ban is recorded as a new Lifted row, not by deleting the ban it
 * undoes. Current state lives on `login.state` and `login.unban_time`.
 */
enum BanType: int
{
    case Lifted = 0;
    case Temporary = 1;
    case Permanent = 2;

    public function label(): string
    {
        return match ($this) {
            self::Lifted => 'Unbanned',
            self::Temporary => 'Temporarily banned',
            self::Permanent => 'Permanently banned',
        };
    }
}
