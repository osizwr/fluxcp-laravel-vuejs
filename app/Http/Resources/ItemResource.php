<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Rathena\AttributeDecoder;
use App\Services\Rathena\ReferenceTables;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item row from the merged item table.
 *
 * The resource wraps a plain stdClass from the query builder rather than a
 * model: the merged table is a derived query, not a table Eloquent can own.
 *
 * rAthena's hundred-odd attribute columns are decoded into labelled lists
 * here, so the client receives "Upper Headgear" rather than
 * `location_head_top: 1` and does not need its own copy of the mapping.
 */
final class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;
        $decoder = app(AttributeDecoder::class);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name_english'] ?? ''),
            'aegis_name' => (string) ($row['name_aegis'] ?? ''),

            'type' => $decoder->itemType($row['type'] ?? null),
            'subtype' => $row['subtype'] ?? null,

            /*
             * Which table the row came from. The legacy listing used this to
             * separate stock entries from the server's own, and a player
             * looking at an item wants to know it is custom.
             */
            'is_custom' => ($row['origin_table'] ?? '') !== $this->stockTable($row),
            'origin_table' => $row['origin_table'] ?? null,

            'price' => [
                'buy' => $this->nullableInt($row['price_buy'] ?? null),
                'sell' => $this->nullableInt($row['price_sell'] ?? null),
            ],

            'weight' => $this->nullableInt($row['weight'] ?? null),
            'attack' => $this->nullableInt($row['attack'] ?? null),
            'defense' => $this->nullableInt($row['defense'] ?? null),
            'range' => $this->nullableInt($row['range'] ?? null),
            'slots' => (int) ($row['slots'] ?? 0),
            'weapon_level' => $this->nullableInt($row['weapon_level'] ?? null),
            'view' => $this->nullableInt($row['view'] ?? null),
            'gender' => $row['gender'] ?? null,

            'equip_level' => [
                'min' => $this->nullableInt($row['equip_level_min'] ?? null),
                'max' => $this->nullableInt($row['equip_level_max'] ?? null),
            ],

            'refineable' => (int) ($row['refineable'] ?? 0) !== 0,

            'equip_locations' => $decoder->equipLocations($row),
            'jobs' => $decoder->jobs($row),
            'classes' => $decoder->classes($row),
            'trade_restrictions' => $decoder->tradeRestrictions($row),
            'flags' => $decoder->itemFlags($row),

            /*
             * The item script, shown only on the detail view. It is rAthena
             * script source and can be long, so the listing omits it rather
             * than sending a few hundred kilobytes per page.
             */
            'script' => $this->when(
                $request->routeIs('item.view'),
                fn (): ?string => $row['script'] ?? null,
            ),
        ];
    }

    /**
     * The base table for this server, used to decide whether a row is custom.
     */
    private function stockTable(array $row): string
    {
        return (string) app(ReferenceTables::class)
            ->tablesFor('items')['base'];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
