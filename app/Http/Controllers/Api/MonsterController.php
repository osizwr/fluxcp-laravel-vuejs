<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\MonsterResource;
use App\Services\Rathena\ReferenceTables;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The monster database.
 *
 * Ports modules/monster/index.php and view.php. As with items, the read goes
 * through the merge so a server's custom monsters appear with their own stats
 * (D6).
 *
 * The column names are resolved per server rather than hardcoded: rAthena has
 * shipped `ID`/`iName`/`LV` and `id`/`name_english`/`level` for this table
 * across versions, and a panel that assumes one shows an empty listing on the
 * other.
 */
final class MonsterController
{
    public function __construct(private readonly ReferenceTables $tables) {}

    /**
     * @throws ValidationException
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'id' => ['nullable', 'integer', 'min:0'],
            'name' => ['nullable', 'string', 'max:50'],
            'level_min' => ['nullable', 'integer', 'min:0'],
            'level_max' => ['nullable', 'integer', 'min:0'],
            'mvp' => ['nullable', 'boolean'],
            'origin' => ['nullable', 'in:stock,custom'],
            /*
             * By name or by number, because rAthena stores them both ways and
             * an operator linking to a filtered list should not have to know
             * which. Resolved against the vocabulary before it reaches SQL,
             * so an unknown value is a validation error rather than a query.
             */
            'race' => ['nullable', 'string', 'max:20'],
            'size' => ['nullable', 'string', 'max:20'],
            'element' => ['nullable', 'string', 'max:20'],
        ]);

        $columns = $this->columnNames();
        $query = $this->tables->monsters();

        $this->applyFilters($query, $filters, $columns);

        $list = new ListQuery(
            sortable: [
                'id' => $columns['id'],
                'name' => $columns['name'],
                'level' => $columns['level'],
                'hp' => $columns['hp'],
                'experience' => $columns['exp'],
            ],
            defaultSort: 'id',
            defaultDirection: 'asc',
        );

        return MonsterResource::collection($list->paginate($query, $request))
            ->additional(['meta' => $list->metadata($request)]);
    }

    public function show(Request $request, int $monster): JsonResponse
    {
        $columns = $this->columnNames();

        $row = $this->tables->monsters()->where($columns['id'], $monster)->first();

        abort_if($row === null, 404, 'No such monster.');

        return MonsterResource::make($row)->response();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $columns
     */
    private function applyFilters(Builder $query, array $filters, array $columns): void
    {
        if (($id = $filters['id'] ?? null) !== null) {
            $query->where($columns['id'], (int) $id);
        }

        $name = trim((string) ($filters['name'] ?? ''));

        if ($name !== '') {
            // Wildcards in the term are escaped so a search for "100%" does
            // not match every monster.
            $escaped = addcslashes($name, '%_\\');

            $query->where($columns['name'], 'like', "%{$escaped}%");
        }

        if (($min = $filters['level_min'] ?? null) !== null) {
            $query->where($columns['level'], '>=', (int) $min);
        }

        if (($max = $filters['level_max'] ?? null) !== null) {
            $query->where($columns['level'], '<=', (int) $max);
        }

        if (array_key_exists('mvp', $filters) && $filters['mvp'] !== null) {
            // An MVP is identified by carrying MVP experience, which is how
            // rAthena itself distinguishes them.
            $query->where($columns['mvp_exp'], filter_var($filters['mvp'], FILTER_VALIDATE_BOOL) ? '>' : '=', 0);
        }

        foreach (['race' => 'monster_races', 'size' => 'monster_sizes'] as $field => $vocabulary) {
            $term = trim((string) ($filters[$field] ?? ''));

            if ($term === '' || ! isset($columns[$field])) {
                continue;
            }

            /*
             * Matched against every stored form that carries the chosen label,
             * so `race=Demi-Human` finds the monsters a pre-renewal server
             * stored as 7 and a renewal one stored as `Demihuman`.
             *
             * Compared as text on both sides, which is the whole point of the
             * cast. Without it MySQL coerces the other way: a varchar column
             * compared against the integer 0 turns `Demihuman` into 0 as well,
             * so filtering for Formless -- which is race 0 -- returned every
             * monster whose race was a word.
             */
            $forms = array_map('strval', $this->storedFormsFor($term, $vocabulary));

            $query->whereIn(new Expression("cast({$columns[$field]} as char)"), $forms);
        }

        $element = trim((string) ($filters['element'] ?? ''));

        if ($element !== '' && isset($columns['element'])) {
            $this->filterByElement($query, $columns['element'], $element);
        }

        $origin = (string) ($filters['origin'] ?? '');

        if ($origin !== '') {
            $base = $this->tables->tablesFor('monsters')['base'];

            $origin === 'stock'
                ? $query->where('origin_table', $base)
                : $query->where('origin_table', '!=', $base);
        }
    }

    /**
     * The filter vocabulary, for building a search form.
     *
     * Only the labels, deduplicated: the stored forms are the panel's problem
     * and a client that offered both `7` and `Demihuman` for Demi-Human would
     * be showing the reader the storage.
     */
    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'races' => $this->labels('monster_races'),
                'sizes' => $this->labels('monster_sizes'),
                'elements' => $this->labels('monster_elements'),
                'sortable' => ['id', 'name', 'level', 'hp', 'experience'],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function labels(string $vocabulary): array
    {
        return array_values(array_unique(array_map(
            'strval',
            (array) config("rathena_reference.{$vocabulary}", []),
        )));
    }

    /**
     * The column names this server's mob table actually uses.
     *
     * @return array<string, string>
     */
    private function columnNames(): array
    {
        $available = $this->tables->monsterColumns();

        $pick = static function (array $candidates, string $fallback) use ($available): string {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $available, true)) {
                    return $candidate;
                }
            }

            return $fallback;
        };

        return [
            'id' => $pick(['ID', 'id'], 'id'),
            'name' => $pick(['iName', 'name_english', 'kName', 'name_japanese'], 'name_english'),
            'level' => $pick(['LV', 'level'], 'level'),
            'hp' => $pick(['HP', 'hp'], 'hp'),
            'exp' => $pick(['EXP', 'base_exp'], 'base_exp'),
            'mvp_exp' => $pick(['MEXP', 'mvp_exp'], 'mvp_exp'),
            'race' => $pick(['Race', 'race'], 'race'),
            'size' => $pick(['Size', 'size'], 'size'),
            'element' => $pick(['Element', 'element'], 'element'),
        ];
    }

    /**
     * Every stored value that carries a given label.
     *
     * The vocabularies are keyed by both the number and the word, so a label
     * normally has two keys: 7 and `Demihuman` both mean Demi-Human. Matching
     * on all of them means one filter works whichever table the server has.
     *
     * @return list<int|string>
     *
     * @throws ValidationException when the term names nothing.
     */
    private function storedFormsFor(string $term, string $vocabulary): array
    {
        $map = (array) config("rathena_reference.{$vocabulary}", []);

        // The term may be the label, the word key, or the number. Whichever it
        // is, it resolves to a label first and the label back to every key.
        $label = $map[$term] ?? $map[ucfirst(strtolower($term))] ?? null;

        if ($label === null && is_numeric($term)) {
            $label = $map[(int) $term] ?? null;
        }

        if ($label === null) {
            $label = $this->labelMatching($map, $term);
        }

        if ($label === null) {
            throw ValidationException::withMessages([
                str_contains($vocabulary, 'race') ? 'race' : 'size' => "There is no such {$vocabulary} as \"{$term}\".",
            ]);
        }

        return array_keys(array_filter(
            $map,
            static fn (mixed $value): bool => $value === $label,
        ));
    }

    /**
     * A label matched without regard to case or punctuation, so `demihuman`
     * finds `Demi-Human`.
     *
     * @param  array<array-key, mixed>  $map
     */
    private function labelMatching(array $map, string $term): ?string
    {
        $wanted = preg_replace('/[^a-z0-9]/', '', strtolower($term));

        foreach ($map as $label) {
            if (preg_replace('/[^a-z0-9]/', '', strtolower((string) $label)) === $wanted) {
                return (string) $label;
            }
        }

        return null;
    }

    /**
     * Filter by element, allowing for the level packed into the column.
     *
     * A pre-renewal server stores `element + level * 20`, so every level of
     * Fire is a different stored number and matching one value would find only
     * the monsters of that level. The condition is therefore a modulo on the
     * numeric form, and an equality on the word form.
     *
     * @throws ValidationException
     */
    private function filterByElement(Builder $query, string $column, string $term): void
    {
        $map = (array) config('rathena_reference.monster_elements', []);

        $label = $map[$term] ?? $map[ucfirst(strtolower($term))] ?? null;

        if ($label === null && is_numeric($term)) {
            $label = $map[(int) $term % 20] ?? null;
        }

        $label ??= $this->labelMatching($map, $term);

        if ($label === null) {
            throw ValidationException::withMessages([
                'element' => "There is no such element as \"{$term}\".",
            ]);
        }

        $numeric = array_values(array_filter(
            array_keys(array_filter($map, static fn (mixed $v): bool => $v === $label)),
            'is_int',
        ));

        $query->where(function (Builder $inner) use ($column, $label, $numeric): void {
            // The word form, compared as text so a numeric column cannot
            // coerce the label to 0 and match everything.
            $inner->whereRaw("cast({$column} as char) = ?", [$label]);

            foreach ($numeric as $value) {
                /*
                 * The numeric form. The level lives in the same column on a
                 * pre-renewal server as `element + level * 20`, so every level
                 * of this element has to match -- and the regular expression
                 * guards the modulo, because `'Water' % 20` is 0 in MySQL and
                 * would make every word-valued row look like Neutral.
                 */
                $inner->orWhereRaw(
                    "{$column} regexp '^[0-9]+$' and {$column} % 20 = ?",
                    [$value],
                );
            }
        });
    }
}
