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

            /*
             * Reported as stored and as a name. rAthena holds these two ways:
             * the pre-renewal `mob_db` keeps a number, and `mob_db_re`,
             * generated from the YAML, keeps a word. Casting to int was wrong
             * on the second -- `(int) 'Formless'` is 0, so every monster on a
             * renewal server reported race 0 and size 0.
             */
            'size' => $this->raw($row, ['Size', 'size']),
            'size_name' => $this->named($row, ['Size', 'size'], 'monster_sizes'),
            'race' => $this->raw($row, ['Race', 'race']),
            'race_name' => $this->named($row, ['Race', 'race'], 'monster_races'),
            'element' => $this->raw($row, ['Element', 'element']),
            'element_name' => $this->elementName($row),
            /*
             * `mob_db` packs the element level into the element column as
             * `element + level * 20`, so a stored 23 is a level 1 Fire
             * monster. `mob_db_re` keeps the level in its own column.
             */
            'element_level' => $this->elementLevel($row),

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

    /**
     * A value as rAthena stored it: an int where it is numeric, the string
     * otherwise, null when the column is absent.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     */
    private function raw(array $row, array $candidates): int|string|null
    {
        $value = $this->pick($row, $candidates, null);

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : (string) $value;
    }

    /**
     * The label for a stored value, looked up by whichever form it is in.
     *
     * The vocabularies in config/rathena_reference.php are keyed by both the
     * number and the word for exactly this reason. A value with no entry
     * yields null rather than a guess, so a server on a newer rAthena than
     * this table knows about shows the raw value instead of a wrong name.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     */
    private function named(array $row, array $candidates, string $vocabulary): ?string
    {
        $value = $this->raw($row, $candidates);

        if ($value === null) {
            return null;
        }

        $map = (array) config("rathena_reference.{$vocabulary}", []);

        return isset($map[$value]) ? (string) $map[$value] : null;
    }

    /**
     * The element name, with `mob_db`'s packed level removed first.
     *
     * @param  array<string, mixed>  $row
     */
    private function elementName(array $row): ?string
    {
        $value = $this->raw($row, ['Element', 'element']);

        if ($value === null) {
            return null;
        }

        $map = (array) config('rathena_reference.monster_elements', []);

        if (is_string($value)) {
            return isset($map[$value]) ? (string) $map[$value] : null;
        }

        $element = $value % 20;

        return isset($map[$element]) ? (string) $map[$element] : null;
    }

    /**
     * The element level: its own column on a renewal server, and the high part
     * of the element column on a pre-renewal one.
     *
     * @param  array<string, mixed>  $row
     */
    private function elementLevel(array $row): ?int
    {
        $own = $this->pick($row, ['ElementLevel', 'element_level'], null);

        if ($own !== null && $own !== '') {
            return (int) $own;
        }

        $value = $this->raw($row, ['Element', 'element']);

        return is_int($value) ? intdiv($value, 20) : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
