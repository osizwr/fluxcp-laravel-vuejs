<?php

declare(strict_types=1);

namespace App\Support\Rathena\Reference;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * rAthena's base reference table overlaid with the server's custom one.
 *
 * rAthena ships `item_db` and `mob_db` and leaves `item_db2` and `mob_db2` for
 * the server owner's own entries. A row in the `2` table *replaces* the row
 * with the same id in the base table, and adds it when there is none. A panel
 * that reads only the base table shows stock data and silently ignores every
 * custom item on the server, which looks correct on a fresh install and is
 * wrong on every real one.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a temporary table
 * ---------------------------------------------------------------------------
 *
 * FluxCP's Flux_TemporaryTable did the merge by issuing
 * `CREATE TEMPORARY TABLE`, copying both tables into it, reading it, and
 * dropping it -- per request, per page view. That has four problems:
 *
 *   1. It needs the CREATE TEMPORARY TABLES privilege, which a read-only panel
 *      user has no other reason to hold.
 *   2. A temporary table is per-connection state. Laravel reconnects on a
 *      dropped connection and would silently lose it mid-request.
 *   3. It copies both tables in full on every request. `item_db_re` is tens of
 *      thousands of rows.
 *   4. It cannot be composed with pagination, so the legacy pages fetched
 *      everything and sliced in PHP.
 *
 * The same result is a derived table: the base rows that the override does not
 * replace, unioned with all the override rows. It is one statement, needs no
 * privileges beyond SELECT, and because it returns a query builder the caller
 * can filter, sort and paginate it in SQL.
 *
 * `origin_table` is preserved because the legacy item listing filtered on it
 * to separate stock entries from custom ones.
 *
 * See docs/MIGRATION_DECISIONS.md (D6).
 */
final readonly class MergedTable
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    /**
     * A query over the merged table, ready to be filtered and paginated.
     *
     * @param  string  $alias  The name the result is addressable by, matching
     *                         the legacy temporary table names (`items`,
     *                         `monsters`) so ported WHERE clauses still read
     *                         the same.
     */
    public function query(
        string $connection,
        string $base,
        ?string $override,
        string $alias,
    ): Builder {
        $db = $this->connections->connection($connection);

        if (! $this->tableExists($connection, $base)) {
            throw new RuntimeException(
                "The reference table [{$base}] does not exist on connection [{$connection}]. "
                .'Check the renewal setting for this server: a pre-renewal server has '
                .'item_db/mob_db, a renewal one has item_db_re/mob_db_re.'
            );
        }

        $columns = $this->columns($connection, $base);

        /*
         * An absent override table is the normal case on a server that has not
         * added custom entries, so it is not an error -- the base table alone
         * is the correct answer, and raising here would break the item pages
         * on a stock install.
         */
        if ($override === null || ! $this->tableExists($connection, $override)) {
            return $db->query()->fromSub(
                $db->query()
                    ->from($base)
                    ->select($this->qualified($base, $columns))
                    ->selectRaw('? as origin_table', [$base]),
                $alias,
            );
        }

        $this->assertOverrideIsCompatible($connection, $base, $override, $columns);

        $key = $this->keyColumn($connection, $base);

        $baseRows = $db->query()
            ->from($base)
            ->select($this->qualified($base, $columns))
            ->selectRaw('? as origin_table', [$base])
            /*
             * NOT EXISTS rather than a LEFT JOIN with a null check: it stops
             * at the first match, uses the override's primary key index, and
             * cannot duplicate a base row if the override somehow holds two
             * with the same id.
             */
            ->whereNotExists(fn (Builder $q) => $q
                ->from($override)
                ->selectRaw('1')
                ->whereColumn("{$override}.{$key}", "{$base}.{$key}"));

        $overrideRows = $db->query()
            ->from($override)
            ->select($this->qualified($override, $columns))
            ->selectRaw('? as origin_table', [$override]);

        return $db->query()->fromSub($baseRows->unionAll($overrideRows), $alias);
    }

    /*
    |--------------------------------------------------------------------------
    | Schema introspection
    |--------------------------------------------------------------------------
    |
    | Cached, because these run on every request that reads an item or a mob
    | and the answer only changes when the emulator is upgraded.
    |
    */

    /**
     * @return list<string>
     */
    public function columns(string $connection, string $table): array
    {
        return Cache::remember(
            "rathena.schema.{$connection}.{$table}.columns",
            now()->addHours(12),
            fn (): array => $this->connections
                ->connection($connection)
                ->getSchemaBuilder()
                ->getColumnListing($table),
        );
    }

    /**
     * The column rows are matched on.
     *
     * Detected rather than configured, because it is not consistent across
     * rAthena's own tables or across versions: `item_db_re` uses `id` and
     * `mob_db_re` has used both `ID` and `id`. Hardcoding either produces a
     * merge that silently returns every row twice on the version that
     * disagrees.
     */
    public function keyColumn(string $connection, string $table): string
    {
        $key = Cache::remember(
            "rathena.schema.{$connection}.{$table}.key",
            now()->addHours(12),
            function () use ($connection, $table): ?string {
                foreach ($this->connections->connection($connection)
                    ->getSchemaBuilder()
                    ->getIndexes($table) as $index) {
                    if (($index['primary'] ?? false) === true && count($index['columns'] ?? []) === 1) {
                        return (string) $index['columns'][0];
                    }
                }

                return null;
            },
        );

        if ($key === null) {
            throw new RuntimeException(
                "The reference table [{$table}] has no single-column primary key, so rows "
                .'cannot be matched between it and its override table.'
            );
        }

        return $key;
    }

    private function tableExists(string $connection, string $table): bool
    {
        return Cache::remember(
            "rathena.schema.{$connection}.{$table}.exists",
            now()->addHours(12),
            fn (): bool => $this->connections
                ->connection($connection)
                ->getSchemaBuilder()
                ->hasTable($table),
        );
    }

    /**
     * Both halves of a UNION must present the same columns in the same order.
     *
     * Checked explicitly so a mismatch is a message naming the column, rather
     * than a driver error about column counts from a query the caller never
     * wrote.
     *
     * @param  list<string>  $columns
     */
    private function assertOverrideIsCompatible(
        string $connection,
        string $base,
        string $override,
        array $columns,
    ): void {
        $missing = array_diff($columns, $this->columns($connection, $override));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The override table [%s] is missing %s present in [%s]: %s. '
                .'The two must have the same columns for custom entries to be merged.',
                $override,
                count($missing) === 1 ? 'a column' : 'columns',
                $base,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function qualified(string $table, array $columns): array
    {
        return array_map(static fn (string $column): string => "{$table}.{$column}", $columns);
    }
}
