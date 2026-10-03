<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Rathena\AttributeDecoder;
use App\Services\Rathena\ReferenceTables;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A monster row from the merged monster table.
 *
 * rAthena's mob table has used several column spellings across versions, so
 * each field is read through a small list of candidates rather than one name.
 * A panel that reads `iName` and nothing else shows blank names on a server
 * running a schema where it is `name_english`.
 */
final class MonsterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;
        $decoder = app(AttributeDecoder::class);

        return [
            'id' => (int) $this->pick($row, ['ID', 'id'], 0),
            'name' => (string) $this->pick($row, ['iName', 'name_english', 'kName', 'name_japanese'], ''),
            'aegis_name' => (string) $this->pick($row, ['Sprite', 'name_aegis'], ''),

            'level' => (int) $this->pick($row, ['LV', 'level'], 0),
            'hp' => (int) $this->pick($row, ['HP', 'hp'], 0),
            'sp' => (int) $this->pick($row, ['SP', 'sp'], 0),

            'experience' => [
                'base' => (int) $this->pick($row, ['EXP', 'base_exp'], 0),
                'job' => (int) $this->pick($row, ['JEXP', 'job_exp'], 0),
                'mvp' => (int) $this->pick($row, ['MEXP', 'mvp_exp'], 0),
            ],

            'attack' => [
                'min' => (int) $this->pick($row, ['ATK1', 'attack_min'], 0),
                'max' => (int) $this->pick($row, ['ATK2', 'attack_max'], 0),
            ],

            'defense' => (int) $this->pick($row, ['DEF', 'defense'], 0),
            'magic_defense' => (int) $this->pick($row, ['MDEF', 'magic_defense'], 0),

            'size' => $this->nullableInt($this->pick($row, ['Size', 'size'], null)),
            'race' => $this->nullableInt($this->pick($row, ['Race', 'race'], null)),
            'element' => $this->nullableInt($this->pick($row, ['Element', 'element'], null)),

            'is_mvp' => (int) $this->pick($row, ['MEXP', 'mvp_exp'], 0) > 0
                || (int) ($row['mode_mvp'] ?? 0) !== 0,

            'modes' => $decoder->monsterModes($row),

            'is_custom' => ($row['origin_table'] ?? '') !== $this->stockTable(),
            'origin_table' => $row['origin_table'] ?? null,
        ];
    }

    /**
     * The first candidate column present on the row.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     */
    private function pick(array $row, array $candidates, mixed $default): mixed
    {
        foreach ($candidates as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                return $row[$column];
            }
        }

        return $default;
    }

    private function stockTable(): string
    {
        return (string) app(ReferenceTables::class)
            ->tablesFor('monsters')['base'];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
