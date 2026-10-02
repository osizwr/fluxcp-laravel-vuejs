<?php

declare(strict_types=1);

namespace App\Support\Rathena;

/**
 * The single place that decides what a generated database connection is
 * called.
 *
 * Connection names are derived rather than configured, because they are an
 * implementation detail of runtime registration (see
 * docs/MIGRATION_DECISIONS.md, D5) and must agree between the registrar, the
 * models and the migrations. Underscores are used rather than dots so a name
 * is never ambiguous when read back out of the config repository with
 * dot notation.
 */
final class ConnectionNaming
{
    public const PREFIX = 'ra';

    public static function login(string $groupKey): string
    {
        return self::join([self::PREFIX, $groupKey, 'login']);
    }

    public static function charMap(string $groupKey, string $charMapKey): string
    {
        return self::join([self::PREFIX, $groupKey, 'charmap', $charMapKey]);
    }

    public static function logs(string $groupKey): string
    {
        return self::join([self::PREFIX, $groupKey, 'logs']);
    }

    public static function web(string $groupKey): string
    {
        return self::join([self::PREFIX, $groupKey, 'web']);
    }

    /**
     * @param  list<string>  $parts
     */
    private static function join(array $parts): string
    {
        return implode('_', array_map(
            static fn (string $part): string => preg_replace('/[^a-z0-9]+/', '', strtolower($part)) ?? '',
            $parts,
        ));
    }
}
