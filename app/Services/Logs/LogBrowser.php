<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Enums\AccountLevel;
use App\Support\Rathena\Reference\MergedTable;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Reads one of the declared log tables.
 *
 * FluxCP had 22 near-identical modules for this, each repeating the same
 * count-then-page-then-render sequence, which is why their sort handling and
 * date filters had drifted apart. The views are declarations in
 * config/log_browsers.php and this reads any of them.
 *
 * ---------------------------------------------------------------------------
 * Columns are intersected with the real schema
 * ---------------------------------------------------------------------------
 *
 * rAthena's log tables vary by version, and which of them have any rows at all
 * depends on what the operator enabled in `log_athena.conf`. A view declares
 * the columns it would like; only the ones the table actually has are
 * selected. A hardcoded SELECT would turn "this server logs slightly
 * differently" into a 500 on an admin page.
 *
 * A table that does not exist at all reports as unavailable rather than
 * failing, because an operator who has never enabled a log type has no such
 * table.
 */
final readonly class LogBrowser
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
        private MergedTable $schema,
    ) {}

    /**
     * Every declared view, with the level each needs.
     *
     * @return array<string, array<string, mixed>>
     */
    public function views(): array
    {
        return (array) config('log_browsers', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(string $key): array
    {
        $views = $this->views();

        if (! array_key_exists($key, $views)) {
            throw new InvalidArgumentException("Unknown log view [{$key}].");
        }

        return $views[$key];
    }

    public function levelFor(string $key): AccountLevel
    {
        $level = $this->view($key)['level'] ?? AccountLevel::Administrator;

        return $level instanceof AccountLevel ? $level : AccountLevel::Administrator;
    }

    /**
     * Whether this server actually has the table behind a view.
     */
    public function isAvailable(string $key): bool
    {
        $view = $this->view($key);

        return $this->connectionFor($view)
            ->getSchemaBuilder()
            ->hasTable((string) $view['table']);
    }

    /**
     * The columns a view can actually show here, in declared order.
     *
     * @return array<string, array{label: string, type: string, vocabulary?: string}>
     */
    public function columnsFor(string $key): array
    {
        $view = $this->view($key);

        if (! $this->isAvailable($key)) {
            return [];
        }

        $present = $this->schema->columns(
            $this->connectionNameFor($view),
            (string) $view['table'],
        );

        $columns = [];

        foreach ((array) $view['columns'] as $column => $meta) {
            if (in_array($column, $present, true)) {
                $columns[$column] = [
                    'label' => (string) ($meta['label'] ?? $column),
                    'type' => (string) ($meta['type'] ?? 'text'),
                ];

                /*
                 * Carried through for a `vocabulary` column, which names the
                 * reference map its stored code is resolved against. This
                 * projection is rebuilt rather than passed along, so a key
                 * not listed here is silently dropped -- which is how the
                 * coded columns came to be declared and never decoded.
                 */
                if (isset($meta['vocabulary'])) {
                    $columns[$column]['vocabulary'] = (string) $meta['vocabulary'];
                }
            }
        }

        return $columns;
    }

    /**
     * Build the query for a view, with the request's filters applied.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(string $key, array $filters = []): Builder
    {
        $view = $this->view($key);
        $columns = $this->columnsFor($key);

        $query = $this->connectionFor($view)
            ->table((string) $view['table'])
            ->select(array_keys($columns));

        $this->applyFilters($query, $view, $columns, $filters);

        return $query;
    }

    /**
     * Columns a request may sort by, as public name => column.
     *
     * Every selected column is sortable except the free-text ones, which are
     * long and whose ordering tells nobody anything.
     *
     * @return array<string, string>
     */
    public function sortableFor(string $key): array
    {
        $sortable = [];

        foreach ($this->columnsFor($key) as $column => $meta) {
            if (in_array($meta['type'], ['message'], true)) {
                continue;
            }

            $sortable[$column] = $column;
        }

        return $sortable;
    }

    /**
     * The default sort: the view's date column, newest first.
     */
    public function defaultSortFor(string $key): ?string
    {
        $date = (string) ($this->view($key)['date'] ?? '');

        return array_key_exists($date, $this->columnsFor($key)) ? $date : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $view
     * @param  array<string, array{label: string, type: string}>  $columns
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $view, array $columns, array $filters): void
    {
        foreach ((array) ($view['filters'] ?? []) as $column) {
            // A declared filter on a column this schema lacks is skipped
            // rather than queried.
            if (! array_key_exists($column, $columns)) {
                continue;
            }

            $value = $filters[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $type = $columns[$column]['type'];

            if (in_array($type, ['account', 'character', 'monster', 'item', 'number'], true)) {
                $query->where($column, (int) $value);

                continue;
            }

            // Wildcards escaped so a search for "%" does not match everything.
            $query->where($column, 'like', '%'.addcslashes((string) $value, '%_\\').'%');
        }

        $date = (string) ($view['date'] ?? '');

        if ($date === '' || ! array_key_exists($date, $columns)) {
            return;
        }

        if (($from = $filters['from'] ?? null) !== null && $from !== '') {
            $query->where($date, '>=', Carbon::parse((string) $from)->startOfDay());
        }

        if (($to = $filters['to'] ?? null) !== null && $to !== '') {
            // Inclusive of the whole day, which is what somebody picking a date
            // on a form means by "to".
            $query->where($date, '<=', Carbon::parse((string) $to)->endOfDay());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $view
     */
    private function connectionFor(array $view): ConnectionInterface
    {
        return $this->connections->connection($this->connectionNameFor($view));
    }

    /**
     * @param  array<string, mixed>  $view
     */
    private function connectionNameFor(array $view): string
    {
        $group = $this->servers->current();

        return match ((string) ($view['connection'] ?? 'logs')) {
            'login' => $group->loginConnection(),
            'char_map' => $this->servers->currentCharMapServer()->connectionName(),
            default => $group->logsConnection(),
        };
    }
}
