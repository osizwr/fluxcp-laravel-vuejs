<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Page size and sort order for a listing endpoint.
 *
 * Replaces Flux_Paginator, which combined three jobs: slicing the result set,
 * deciding the sort, and rendering the page links and sortable column headers
 * as HTML. Only the first two belong on the server now -- the client renders
 * its own controls from the pagination metadata Laravel already returns.
 *
 * ---------------------------------------------------------------------------
 * The allow-list
 * ---------------------------------------------------------------------------
 *
 * A sort column arrives as a string in the query and ends up in SQL, so the
 * set of acceptable values is declared by the endpoint and nothing else is
 * accepted. The map also lets the public name differ from the column: a
 * listing can expose `?sort=name` over `char.name`, which keeps the schema out
 * of the URL and means renaming a column does not break a bookmarked link.
 *
 * To be accurate about what this replaces: FluxCP was *not* vulnerable here.
 * Its `getSQL()` iterated a hardcoded allow-list and took only the direction
 * from the request. The allow-list is the right design rather than a fix, and
 * an earlier revision of docs/FINAL_MIGRATION_AUDIT.md said otherwise; it has
 * been corrected.
 *
 * ---------------------------------------------------------------------------
 * Nulls
 * ---------------------------------------------------------------------------
 *
 * Ascending sorts put nulls last, which is what the legacy did with its
 * `CASE WHEN col IS NULL THEN 1 ELSE 0 END` prefix. MySQL sorts nulls first
 * ascending, so without it a "sort by last login" puts every account that has
 * never logged in at the top -- which is the least useful thing that column
 * can show.
 */
final readonly class ListQuery
{
    private const ASCENDING = 'asc';

    private const DESCENDING = 'desc';

    /**
     * @param  array<string, string>  $sortable  Public name => column expression.
     * @param  string|null  $defaultSort  A key of $sortable.
     */
    public function __construct(
        public array $sortable = [],
        public ?string $defaultSort = null,
        public string $defaultDirection = self::DESCENDING,
        public bool $nullsLast = true,
    ) {}

    /**
     * Apply the request's paging and sorting to a query.
     *
     * @template TValue
     *
     * @return LengthAwarePaginator<int, TValue>
     *
     * @throws ValidationException
     */
    public function paginate(BuilderContract $query, Request $request): LengthAwarePaginator
    {
        $this->applySort($query, $request);

        return $query
            ->paginate($this->perPage($request))
            // So page 2 of a filtered, sorted listing keeps the filter and the
            // sort in its links.
            ->withQueryString();
    }

    /**
     * How many rows per page, clamped.
     *
     * The cap is not politeness: `per_page` is attacker-controlled, and
     * without it one request can ask for every row in `picklog`.
     */
    public function perPage(Request $request): int
    {
        $default = (int) config('panel.pagination.per_page', 20);
        $max = (int) config('panel.pagination.max_per_page', 100);

        $requested = $request->integer('per_page', $default);

        return max(1, min($requested, $max));
    }

    /**
     * @throws ValidationException
     */
    public function applySort(BuilderContract $query, Request $request): void
    {
        [$key, $direction] = $this->resolveSort($request);

        if ($key === null) {
            return;
        }

        $column = $this->sortable[$key];

        if ($this->nullsLast && $direction === self::ASCENDING) {
            /*
             * The column name here comes from the allow-list, never from the
             * request, which is what makes this raw fragment safe.
             */
            $query->orderByRaw("(CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END) ASC");
        }

        $query->orderBy($column, $direction);
    }

    /**
     * The sort the request asked for, after validation.
     *
     * @return array{0: string|null, 1: string}
     *
     * @throws ValidationException
     */
    public function resolveSort(Request $request): array
    {
        $direction = strtolower((string) $request->string('direction', $this->defaultDirection));

        if (! in_array($direction, [self::ASCENDING, self::DESCENDING], true)) {
            throw ValidationException::withMessages([
                'direction' => 'Sort direction must be asc or desc.',
            ]);
        }

        $requested = $request->string('sort')->toString();

        if ($requested === '') {
            return [$this->defaultSort, $direction];
        }

        if (! array_key_exists($requested, $this->sortable)) {
            /*
             * Reported rather than silently ignored. A listing that quietly
             * falls back to its default sort when asked for one it does not
             * have is one where a typo in a link looks like a data problem.
             */
            throw ValidationException::withMessages([
                'sort' => $this->sortable === []
                    ? 'This listing cannot be sorted.'
                    : 'Sort must be one of: '.implode(', ', array_keys($this->sortable)).'.',
            ]);
        }

        return [$requested, $direction];
    }

    /**
     * What the client needs to render sort controls: which columns can be
     * sorted, and which one is currently applied.
     *
     * @return array<string, mixed>
     */
    public function metadata(Request $request): array
    {
        [$key, $direction] = $this->resolveSort($request);

        return [
            'sortable' => array_keys($this->sortable),
            'sort' => $key,
            'direction' => $direction,
        ];
    }
}
