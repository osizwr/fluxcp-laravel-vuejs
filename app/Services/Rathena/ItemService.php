<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Support\Rathena\CharMapServer;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Searching the item database.
 *
 * Ports the filtering in modules/item/index.php, which built one long SQL
 * string by concatenation. The filters themselves are faithful; how they reach
 * SQL is not.
 *
 * Every comparison operator and every column name used here comes from a map
 * declared in this class. Nothing from the request becomes SQL: a request asks
 * for `attack_op=gt`, and `gt` is looked up to produce `>`. The legacy code
 * did the same for its operators, and this keeps that property explicit rather
 * than incidental.
 */
final readonly class ItemService
{
    /**
     * Comparison operators a request may ask for.
     *
     * Named rather than literal so an operator cannot be smuggled in, and so
     * the API does not require URL-encoding `>=` in a query string.
     */
    private const OPERATORS = [
        'eq' => '=',
        'ne' => '!=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    /**
     * Numeric columns a request may compare against, with the operator
     * parameter that accompanies each.
     */
    private const COMPARABLE = [
        'price_buy',
        'price_sell',
        'weight',
        'attack',
        'defense',
        'range',
        'slots',
        'equip_level_min',
        'weapon_level',
    ];

    public function __construct(
        private ReferenceTables $tables,
        private AttributeDecoder $decoder,
    ) {}

    /**
     * @return list<string>
     */
    public static function operators(): array
    {
        return array_keys(self::OPERATORS);
    }

    /**
     * @return list<string>
     */
    public static function comparableColumns(): array
    {
        return self::COMPARABLE;
    }

    /**
     * Columns the listing may be sorted by, as public name => column.
     *
     * @return array<string, string>
     */
    public static function sortableColumns(): array
    {
        return [
            'id' => 'id',
            'name' => 'name_english',
            'type' => 'type',
            'price_buy' => 'price_buy',
            'price_sell' => 'price_sell',
            'weight' => 'weight',
            'attack' => 'attack',
            'defense' => 'defense',
            'slots' => 'slots',
            'equip_level_min' => 'equip_level_min',
        ];
    }

    /**
     * Build the filtered query.
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters, ?CharMapServer $server = null): Builder
    {
        $query = $this->tables->items($server);

        $this->applyIdentity($query, $filters);
        $this->applyType($query, $filters);
        $this->applyAttributeFlags($query, $filters, $server);
        $this->applyComparisons($query, $filters);
        $this->applyOrigin($query, $filters, $server);

        return $query;
    }

    /**
     * One item by id, or null.
     */
    public function find(int $id, ?CharMapServer $server = null): ?object
    {
        return $this->tables->items($server)->where('id', $id)->first();
    }

    /**
     * The decoder, so a resource can turn a row's attribute columns into
     * labels without resolving it separately.
     */
    public function decoder(): AttributeDecoder
    {
        return $this->decoder;
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyIdentity(Builder $query, array $filters): void
    {
        if (($id = $filters['id'] ?? null) !== null && $id !== '') {
            $query->where('id', (int) $id);
        }

        $name = trim((string) ($filters['name'] ?? ''));

        if ($name !== '') {
            /*
             * Both names are searched because players know items by the
             * English name and scripts refer to the Aegis one, and somebody
             * pasting either should find the item.
             *
             * The LIKE wildcards are added here; characters in the term that
             * are themselves wildcards are escaped, so a search for "100%"
             * does not match everything.
             */
            $escaped = addcslashes($name, '%_\\');

            $query->where(function (Builder $q) use ($escaped): void {
                $q->where('name_english', 'like', "%{$escaped}%")
                    ->orWhere('name_aegis', 'like', "%{$escaped}%");
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyType(Builder $query, array $filters): void
    {
        $type = (string) ($filters['type'] ?? '');

        if ($type === '') {
            return;
        }

        // Checked against the configured map, so only a known type reaches the
        // query and an unknown one is a no-match rather than a free string.
        if (! array_key_exists(strtolower($type), (array) config('rathena_reference.item_types', []))) {
            throw new InvalidArgumentException("Unknown item type [{$type}].");
        }

        $query->where('type', strtolower($type));

        $subtype = (string) ($filters['subtype'] ?? '');

        if ($subtype !== '') {
            $query->where('subtype', $subtype);
        }
    }

    /**
     * Equip location, job and class filters, each of which is a column.
     *
     * The column name comes from the configured map's keys, never from the
     * request, and a column the server's schema does not have is skipped
     * rather than queried -- a pre-renewal `item_db` has no `job_summoner`,
     * and asking for it is an SQL error rather than an empty result.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyAttributeFlags(Builder $query, array $filters, ?CharMapServer $server): void
    {
        $maps = [
            'location' => (array) config('rathena_reference.equip_locations', []),
            'job' => [
                ...(array) config('rathena_reference.equip_jobs.base', []),
                ...(array) config('rathena_reference.equip_jobs.renewal', []),
            ],
            'class' => [
                ...(array) config('rathena_reference.equip_classes.base', []),
                ...(array) config('rathena_reference.equip_classes.renewal', []),
            ],
        ];

        foreach ($maps as $parameter => $map) {
            $requested = (string) ($filters[$parameter] ?? '');

            if ($requested === '') {
                continue;
            }

            if (! array_key_exists($requested, $map)) {
                throw new InvalidArgumentException("Unknown {$parameter} [{$requested}].");
            }

            if (! $this->hasColumn($requested, $server)) {
                /*
                 * Asked for something this schema does not model. Nothing can
                 * match, which is the honest answer rather than an error about
                 * a column the person never named.
                 */
                $query->whereRaw('1 = 0');

                continue;
            }

            $query->where($requested, '>', 0);
        }

        if (array_key_exists('refineable', $filters) && $filters['refineable'] !== null && $filters['refineable'] !== '') {
            $query->where('refineable', filter_var($filters['refineable'], FILTER_VALIDATE_BOOL) ? '>' : '=', 0);
        }
    }

    /**
     * Numeric comparisons, each with a named operator.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyComparisons(Builder $query, array $filters): void
    {
        foreach (self::COMPARABLE as $column) {
            $value = $filters[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $requested = (string) ($filters["{$column}_op"] ?? 'eq');

            if (! array_key_exists($requested, self::OPERATORS)) {
                throw new InvalidArgumentException(
                    "Unknown comparison [{$requested}]. Expected one of: "
                    .implode(', ', self::operators()).'.'
                );
            }

            $query->where($column, self::OPERATORS[$requested], (int) $value);
        }
    }

    /**
     * Stock entries, custom entries, or both.
     *
     * The legacy listing did this with `origin_table LIKE '%item_db'`, which
     * is why the merge preserves that column.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyOrigin(Builder $query, array $filters, ?CharMapServer $server): void
    {
        $origin = (string) ($filters['origin'] ?? '');

        if ($origin === '') {
            return;
        }

        $tables = $this->tables->tablesFor('items', $server);

        match ($origin) {
            'stock' => $query->where('origin_table', $tables['base']),
            'custom' => $query->where('origin_table', '!=', $tables['base']),
            default => throw new InvalidArgumentException(
                "Unknown origin [{$origin}]. Expected 'stock' or 'custom'."
            ),
        };
    }

    /**
     * Whether the server's item table has a given column.
     */
    private function hasColumn(string $column, ?CharMapServer $server): bool
    {
        return in_array($column, $this->columns($server), true);
    }

    /**
     * The item table's columns, so a filter can tell whether this server's
     * schema models the attribute being asked for.
     *
     * @return list<string>
     */
    public function columns(?CharMapServer $server = null): array
    {
        return $this->tables->itemColumns($server);
    }
}
