<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Support\Rathena\CharMapServer;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Decoding a pile of items into something nameable.
 *
 * rAthena stores a stack of items the same way wherever it keeps them --
 * `inventory`, `cart_inventory`, `guild_storage` and `storage` share a column
 * layout -- so the decoding lives here once and each caller supplies only the
 * table and the rows it wants.
 *
 * ---------------------------------------------------------------------------
 * The card columns
 * ---------------------------------------------------------------------------
 *
 * The four `cardN` columns hold either card item ids or, when `card0` is 254
 * or 255, a marker saying the item was forged or brewed rather than slotted.
 * In that case `card2` and `card3` are not cards: together they hold the
 * creator's character id, split across two columns and stored signed, so
 * reassembling it means widening `card2` back into unsigned range before
 * shifting `card3` into the high half. Reading those two columns as cards
 * produces card names for items that have none, which is the bug this layout
 * invites and the legacy panel avoided the same way.
 *
 * `cards_over` counts cards slotted beyond the item's own slot count. A
 * positive number cannot happen on a stock server and is worth surfacing to
 * staff. It is reported rather than corrected: the panel reports what the
 * database holds and never edits somebody's belongings.
 */
final readonly class ItemStacks
{
    /**
     * `card0` values that mark a forged or brewed item rather than a slotted
     * one. 255 is a forged weapon, 254 a brewed consumable; -256 is the same
     * marker read back from a signed column on some builds.
     */
    private const CREATION_MARKERS = [254, 255, -256];

    /** How many random option pairs a renewal item row carries. */
    private const RANDOM_OPTION_SLOTS = 5;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ReferenceTables $reference,
    ) {}

    /**
     * Read and decode a stack.
     *
     * @param  string  $table  One of rAthena's item-holding tables.
     * @param  callable(Builder): Builder  $scope  Narrows the rows, normally
     *                                             to one character or guild.
     * @param  bool  $includeUnidentified  Whether to include items nobody has
     *                                     identified yet. Staff-only, because
     *                                     naming one tells the viewer
     *                                     something the owner does not know.
     * @param  bool  $equippedFirst  Worn items first, for the tables that have
     *                               an `equip` column.
     * @return list<array<string, mixed>>
     */
    public function read(
        CharMapServer $server,
        string $table,
        callable $scope,
        bool $includeUnidentified = false,
        bool $equippedFirst = false,
    ): array {
        $query = $this->connections->connection($server->connectionName())
            ->table($table)
            ->select("{$table}.*");

        $query = $scope($query);

        if (! $includeUnidentified) {
            $query->where("{$table}.identify", '>', 0);
        }

        if ($equippedFirst) {
            // Matches the legacy ordering so a ported screen lists a
            // character's belongings in the same sequence.
            $query->orderByRaw("case when {$table}.equip > 0 then 0 else 1 end");
        }

        $rows = $query
            ->orderBy("{$table}.nameid")
            ->orderByDesc("{$table}.identify")
            ->orderByDesc("{$table}.attribute")
            ->orderBy("{$table}.refine")
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $this->withNames(
            $rows->map(fn (object $row): array => $this->decode($row, $server))->all(),
            $server,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Decoding
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function decode(object $row, CharMapServer $server): array
    {
        $cards = [
            (int) ($row->card0 ?? 0),
            (int) ($row->card1 ?? 0),
            (int) ($row->card2 ?? 0),
            (int) ($row->card3 ?? 0),
        ];

        $created = in_array($cards[0], self::CREATION_MARKERS, true);

        /*
         * On a created item only card1 can be a real card -- card2 and card3
         * are the two halves of the creator's character id.
         */
        $cardIds = array_values(array_filter(
            $created ? [$cards[1]] : $cards,
            static fn (int $id): bool => $id > 0,
        ));

        return [
            'id' => (int) $row->id,
            'item_id' => (int) $row->nameid,
            // Filled in by withNames(), which resolves them in one query.
            'name' => null,
            'type' => null,
            'slots' => 0,
            'cards_over' => 0,
            'created_by' => null,
            'amount' => (int) ($row->amount ?? 0),
            'refine' => (int) ($row->refine ?? 0),
            'identified' => (int) ($row->identify ?? 0) > 0,
            'broken' => (int) ($row->attribute ?? 0) > 0,
            'equipped' => (int) ($row->equip ?? 0) > 0,
            'equip_location' => (int) ($row->equip ?? 0),
            'favourite' => (int) ($row->favorite ?? 0) > 0,
            'bound' => (int) ($row->bound ?? 0),
            'expires_at' => $this->expiry($row),
            'cards' => array_map(
                static fn (int $id): array => ['item_id' => $id, 'name' => null],
                $cardIds,
            ),
            'created_by_char_id' => $created ? $this->creatorCharId($cards) : null,
            'creation_kind' => $created ? ($cards[0] === 254 ? 'brewed' : 'forged') : null,
            'random_options' => $server->renewal ? $this->randomOptions($row) : [],
        ];
    }

    /**
     * Reassemble the creator's character id from its two signed halves.
     *
     * @param  list<int>  $cards
     */
    private function creatorCharId(array $cards): ?int
    {
        $low = $cards[2] < 0 ? $cards[2] + 65536 : $cards[2];
        $id = $low | ($cards[3] << 16);

        return $id > 0 ? $id : null;
    }

    /**
     * Renewal random options, named.
     *
     * The id alone says nothing, so it is resolved against the format strings
     * in config/rathena_reference.php -- `MaxHP +%s`, and the value goes where
     * the `%s` is. An id with no entry keeps its number and no label, because
     * a server on a newer rAthena than this table knows about should show the
     * option rather than hide it.
     *
     * @return list<array{id: int, value: int, label: ?string}>
     */
    private function randomOptions(object $row): array
    {
        $vocabulary = (array) config('rathena_reference.item_random_options', []);
        $options = [];

        for ($slot = 0; $slot < self::RANDOM_OPTION_SLOTS; $slot++) {
            $id = (int) ($row->{"option_id{$slot}"} ?? 0);

            if ($id === 0) {
                continue;
            }

            $value = (int) ($row->{"option_val{$slot}"} ?? 0);
            $format = $vocabulary[$id] ?? null;

            $options[] = [
                'id' => $id,
                'value' => $value,
                'label' => is_string($format) ? sprintf($format, $value) : null,
            ];
        }

        return $options;
    }

    /**
     * rAthena writes 0 for an item that does not expire.
     */
    private function expiry(object $row): ?string
    {
        $stamp = (int) ($row->expire_time ?? 0);

        return $stamp > 0 ? date(DATE_ATOM, $stamp) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Names
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve item names, slot counts, card names and creator names.
     *
     * Names come through the item merge, so a server's own `item_db2` entries
     * are named by their custom values. The legacy achieved this by shadowing
     * `items` with a temporary table for the whole request; this reads the
     * merge explicitly instead.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withNames(array $items, CharMapServer $server): array
    {
        $itemIds = [];
        $charIds = [];

        foreach ($items as $item) {
            $itemIds[] = $item['item_id'];

            foreach ($item['cards'] as $card) {
                $itemIds[] = $card['item_id'];
            }

            if ($item['created_by_char_id'] !== null) {
                $charIds[] = $item['created_by_char_id'];
            }
        }

        $names = $this->itemNames(array_values(array_unique($itemIds)), $server);
        $creators = $this->characterNames(array_values(array_unique($charIds)), $server);

        foreach ($items as $index => $item) {
            $meta = $names[$item['item_id']] ?? null;

            $items[$index]['name'] = $meta['name'] ?? null;
            $items[$index]['type'] = $meta['type'] ?? null;
            $items[$index]['slots'] = (int) ($meta['slots'] ?? 0);

            foreach ($item['cards'] as $position => $card) {
                $items[$index]['cards'][$position]['name'] = $names[$card['item_id']]['name'] ?? null;
            }

            /*
             * Counted only now that the slot count is known. A created item is
             * excluded because its card columns are not cards, and fewer cards
             * than slots is an ordinary item with room left.
             */
            $items[$index]['cards_over'] = $item['creation_kind'] === null
                ? max(0, count($item['cards']) - $items[$index]['slots'])
                : 0;

            if ($item['created_by_char_id'] !== null) {
                $items[$index]['created_by'] = $creators[$item['created_by_char_id']] ?? null;
            }
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{name: ?string, type: mixed, slots: int}>
     */
    private function itemNames(array $ids, CharMapServer $server): array
    {
        if ($ids === []) {
            return [];
        }

        $key = $this->reference->keyColumn('items', $server);

        return $this->reference->items($server)
            ->whereIn("items.{$key}", $ids)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->{$key} => [
                    'name' => $this->itemName($row),
                    'type' => $row->type ?? null,
                    'slots' => (int) ($row->slots ?? 0),
                ],
            ])
            ->all();
    }

    /**
     * rAthena has named this column differently across versions, so whichever
     * is present and non-empty wins rather than assuming one spelling.
     */
    private function itemName(object $row): ?string
    {
        foreach (['name_english', 'name_japanese', 'name_aegis', 'name'] as $column) {
            $value = $row->{$column} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function characterNames(array $ids, CharMapServer $server): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->connections->connection($server->connectionName())
            ->table('char')
            ->select(['char_id', 'name'])
            ->whereIn('char_id', $ids)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->char_id => (string) $row->name])
            ->all();
    }
}
