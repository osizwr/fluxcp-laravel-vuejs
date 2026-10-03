<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\MonsterResource;
use App\Services\Rathena\ReferenceTables;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
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

        $origin = (string) ($filters['origin'] ?? '');

        if ($origin !== '') {
            $base = $this->tables->tablesFor('monsters')['base'];

            $origin === 'stock'
                ? $query->where('origin_table', $base)
                : $query->where('origin_table', '!=', $base);
        }
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
        ];
    }
}
