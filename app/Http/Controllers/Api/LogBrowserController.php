<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Logs\LogBrowser;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads the declared log views.
 *
 * Ports the 22 cplog/* and logdata/* modules. One endpoint, parameterised by
 * the view, because they differed only in which table they read -- and
 * because 22 copies of the same pagination is how their sort handling drifted
 * apart in the first place.
 *
 * ---------------------------------------------------------------------------
 * Authorisation
 * ---------------------------------------------------------------------------
 *
 * The route is held at JuniorGameMaster by the permission map, and each view
 * declares its own minimum on top of that. They are not all equivalent: the
 * item and zeny logs are everyday moderation, while the chat log is every
 * private message players have sent each other and the transaction log is
 * payment data.
 */
final class LogBrowserController
{
    public function __construct(private readonly LogBrowser $logs) {}

    /**
     * Which views this viewer may open.
     *
     * Published so the client renders a menu of what is actually available
     * rather than a list that 403s on click, and so a view whose table does
     * not exist on this server is marked rather than hidden -- an operator
     * looking for the chat log should be told it is off, not left wondering.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        $level = $account->accountLevel();
        $views = [];

        foreach ($this->logs->views() as $key => $view) {
            if (! $level->satisfies($this->logs->levelFor($key))) {
                continue;
            }

            $views[] = [
                'key' => $key,
                'label' => (string) $view['label'],
                'legacy' => (string) ($view['legacy'] ?? ''),
                'available' => $this->logs->isAvailable($key),
            ];
        }

        return response()->json(['data' => $views]);
    }

    /**
     * One view's rows.
     *
     * @throws ValidationException
     */
    public function show(Request $request, string $view): JsonResponse
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        try {
            $definition = $this->logs->view($view);
        } catch (InvalidArgumentException) {
            abort(404, 'No such log.');
        }

        abort_unless(
            $account->accountLevel()->satisfies($this->logs->levelFor($view)),
            403,
            'You may not read this log.',
        );

        /*
         * A log type the operator never enabled has no table. Reported as an
         * empty, explicitly unavailable result rather than a 500, because
         * "this server does not log that" is a normal configuration.
         */
        if (! $this->logs->isAvailable($view)) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'key' => $view,
                    'label' => (string) $definition['label'],
                    'available' => false,
                    'reason' => 'This server does not keep that log. It is turned off in log_athena.conf.',
                    'columns' => [],
                    'total' => 0,
                ],
            ]);
        }

        $columns = $this->logs->columnsFor($view);
        $filters = $this->validateFilters($request, $definition, $columns);

        $list = new ListQuery(
            sortable: $this->logs->sortableFor($view),
            defaultSort: $this->logs->defaultSortFor($view),
        );

        $page = $list->paginate($this->logs->query($view, $filters), $request);

        return response()->json([
            'data' => array_map(
                fn (object $row): array => $this->shape($row, $columns),
                $page->items(),
            ),
            'meta' => [
                ...$list->metadata($request),
                'key' => $view,
                'label' => (string) $definition['label'],
                'available' => true,
                // The client builds its table from this rather than carrying
                // its own copy of twenty column lists.
                'columns' => array_map(
                    fn (string $column, array $meta): array => [
                        'key' => $column,
                        'label' => $meta['label'],
                        'type' => $meta['type'],
                    ],
                    array_keys($columns),
                    $columns,
                ),
                'filters' => array_values(array_intersect(
                    (array) ($definition['filters'] ?? []),
                    array_keys($columns),
                )),
                'date_column' => $definition['date'] ?? null,
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Rows as the client consumes them: each value already typed.
     *
     * @param  array<string, array{label: string, type: string}>  $columns
     * @return array<string, mixed>
     */
    private function shape(object $row, array $columns): array
    {
        $shaped = [];

        foreach ($columns as $column => $meta) {
            $value = $row->{$column} ?? null;

            $shaped[$column] = match ($meta['type']) {
                'datetime' => $this->iso($value),
                'boolean' => $value !== null && (int) $value !== 0,
                'number', 'account', 'character', 'monster', 'item' => $value === null
                    ? null
                    : (int) $value,
                'message' => $this->cleanMessage($value),
                /*
                 * A column whose stored value is a code: the single letters
                 * rAthena writes into its pick and feeding logs, and the
                 * numeric outcome in the panel's own sign-in log. Both the
                 * code and its label are reported -- the label is what an
                 * operator reads, and the code is what they will find if they
                 * go to the table themselves.
                 */
                'vocabulary' => $this->decode($value, (string) ($meta['vocabulary'] ?? '')),
                default => $value === null ? null : (string) $value,
            };
        }

        return $shaped;
    }

    /**
     * A stored code and what it means.
     *
     * An unknown code keeps its value and gets no label, because these
     * vocabularies grow with the emulator: a log row written by a newer
     * rAthena than this table knows about should still be readable.
     *
     * @return array{code: string, label: ?string}|null
     */
    private function decode(mixed $value, string $vocabulary): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $map = (array) config("rathena_reference.{$vocabulary}", []);
        $code = (string) $value;
        $label = $map[$code] ?? ($map[(int) $code] ?? null);

        return [
            'code' => $code,
            'label' => is_string($label) ? $label : null,
        ];
    }

    /**
     * rAthena pads chat messages with a `|00` marker, which the legacy
     * stripped with a REPLACE in SQL. Doing it here keeps the query plain and
     * means the stored row is reported as stored.
     */
    private function cleanMessage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace('|00', '', (string) $value);
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array{label: string, type: string}>  $columns
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateFilters(Request $request, array $definition, array $columns): array
    {
        $rules = [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];

        foreach ((array) ($definition['filters'] ?? []) as $column) {
            if (! array_key_exists($column, $columns)) {
                continue;
            }

            $rules[$column] = in_array(
                $columns[$column]['type'],
                ['account', 'character', 'monster', 'item', 'number'],
                true,
            )
                ? ['nullable', 'integer']
                : ['nullable', 'string', 'max:100'];
        }

        return $request->validate($rules);
    }
}
