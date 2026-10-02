<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * rAthena's `login.sex`.
 *
 * Server is not a player gender: rAthena marks its own inter-server accounts
 * with 'S', and they must never be able to sign in to the panel. Every account
 * lookup in the authentication path therefore excludes them, as the legacy
 * panel also did.
 */
enum Gender: string
{
    case Male = 'M';
    case Female = 'F';
    case Server = 'S';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Server => 'Server account',
        };
    }

    public function isPlayer(): bool
    {
        return $this !== self::Server;
    }

    /**
     * The genders a player may actually choose.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Male, self::Female];
    }
}
