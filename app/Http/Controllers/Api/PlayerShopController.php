<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Rathena\ReferenceTables;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Player shops: vending stalls and buying stores.
 *
 * Ports modules/vending/index.php, vending/viewshop.php,
 * buyingstore/index.php and buyingstore/viewshop.php.
 *
 * The two are near-identical in shape -- a listing of open shops and the
 * contents of one -- so they share an implementation parameterised by which
 * pair of tables to read, rather than being four near-copies that drift.
 *
 * Item names come from the merge, so a stall selling the server's own custom
 * item names it correctly instead of showing a bare id (D6).
 */
final class PlayerShopController
{
    /**
     * The tables behind each kind of shop.
     *
     * rAthena names them inconsistently -- `vendings`/`vending_items` against
     * `buyingstores`/`buyingstore_items` -- and the vending items are joined
     * through the seller's cart while buying-store items are not, because a
     * buying store holds no stock.
     */
    private const SHOPS = [
        'vending' => [
            'shops' => 'vendings',
            'items' => 'vending_items',
            'through_cart' => true,
        ],
        'buying' => [
            'shops' => 'buyingstores',
            'items' => 'buyingstore_items',
            'through_cart' => false,
        ],
    ];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
        private readonly ReferenceTables $tables,
    ) {}

    /**
     * Open shops of one kind.
     *
     * @throws ValidationException
     */
    public function index(Request $request, string $kind): JsonResponse
    {
        $tables = $this->tablesFor($kind);

        $request->validate([
            'title' => ['nullable', 'string', 'max:80'],
            'map' => ['nullable', 'string', 'max:32'],
        ]);

        $query = $this->connection()
            ->table($tables['shops'].' as shop')
            ->select([
                'shop.id', 'shop.char_id', 'shop.title', 'shop.map',
                'shop.x', 'shop.y', 'shop.autotrade',
                'ch.name as seller',
            ])
            ->leftJoin('char as ch', 'ch.char_id', '=', 'shop.char_id');

        if (($title = trim((string) $request->string('title'))) !== '') {
            $query->where('shop.title', 'like', '%'.addcslashes($title, '%_\\').'%');
        }

        if (($map = trim((string) $request->string('map'))) !== '') {
            $query->where('shop.map', $map);
        }

        $list = new ListQuery(
            sortable: [
                'title' => 'shop.title',
                'seller' => 'ch.name',
                'map' => 'shop.map',
            ],
            defaultSort: 'title',
            defaultDirection: 'asc',
        );

        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'seller' => [
                    'id' => (int) $row->char_id,
                    'name' => (string) ($row->seller ?? ''),
                ],
                'location' => [
                    'map' => (string) $row->map,
                    'x' => (int) $row->x,
                    'y' => (int) $row->y,
                ],
                'autotrade' => (int) ($row->autotrade ?? 0) !== 0,
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'kind' => $kind,
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * What one shop is selling, or buying.
     */
    public function show(Request $request, string $kind, int $shop): JsonResponse
    {
        $tables = $this->tablesFor($kind);
        $connection = $this->connection();

        $row = $connection->table($tables['shops'].' as shop')
            ->select(['shop.id', 'shop.char_id', 'shop.title', 'shop.map', 'shop.x', 'shop.y', 'ch.name as seller'])
            ->leftJoin('char as ch', 'ch.char_id', '=', 'shop.char_id')
            ->where('shop.id', $shop)
            ->first();

        abort_if($row === null, 404, 'That shop is no longer open.');

        return response()->json([
            'data' => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'seller' => [
                    'id' => (int) $row->char_id,
                    'name' => (string) ($row->seller ?? ''),
                ],
                'location' => [
                    'map' => (string) $row->map,
                    'x' => (int) $row->x,
                    'y' => (int) $row->y,
                ],
                'items' => $this->items($kind, $shop),
            ],
        ]);
    }

    /**
     * The shop's stock, with item names resolved through the merge.
     *
     * @return list<array<string, mixed>>
     */
    private function items(string $kind, int $shop): array
    {
        $tables = $this->tablesFor($kind);
        $connection = $this->connection();

        $query = $connection->table($tables['items'].' as line')
            ->where('line.'.($kind === 'vending' ? 'vending_id' : 'buyingstore_id'), $shop);

        if ($tables['through_cart']) {
            /*
             * A vending stall sells out of the seller's cart, so the item id
             * and its refine and cards are on the cart row rather than on the
             * shop line.
             */
            $query->leftJoin('cart_inventory as cart', 'cart.id', '=', 'line.cartinventory_id')
                ->select([
                    'line.index', 'line.amount', 'line.price',
                    'cart.nameid', 'cart.refine', 'cart.identify',
                    'cart.card0', 'cart.card1', 'cart.card2', 'cart.card3',
                ]);
        } else {
            $query->select(['line.index', 'line.amount', 'line.price', 'line.nameid']);
        }

        $lines = $query->orderBy('line.index')->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $names = $this->itemNames($lines->pluck('nameid')->filter()->unique()->all());

        return $lines->map(fn (object $line): array => [
            'item' => [
                'id' => (int) $line->nameid,
                'name' => $names[(int) $line->nameid] ?? null,
            ],
            'amount' => (int) $line->amount,
            'price' => (int) $line->price,
            'refine' => isset($line->refine) ? (int) $line->refine : null,
            'identified' => isset($line->identify) ? (int) $line->identify !== 0 : null,
            'cards' => isset($line->card0)
                ? array_values(array_filter([
                    (int) $line->card0, (int) $line->card1,
                    (int) $line->card2, (int) $line->card3,
                ]))
                : [],
        ])->all();
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, string>
     */
    private function itemNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $key = $this->tables->keyColumn('items');

        return $this->tables->items()
            ->whereIn($key, $ids)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->{$key} => (string) ($row->name_english ?? ''),
            ])
            ->all();
    }

    /**
     * @return array{shops: string, items: string, through_cart: bool}
     */
    private function tablesFor(string $kind): array
    {
        abort_unless(array_key_exists($kind, self::SHOPS), 404);

        return self::SHOPS[$kind];
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection(
            $this->servers->currentCharMapServer()->connectionName(),
        );
    }
}
