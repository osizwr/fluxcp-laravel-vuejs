<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Shop\ItemShop;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The credit shop.
 *
 * Ports modules/purchase/index.php, cart.php, add.php, remove.php, clear.php,
 * checkout.php and pending.php, plus the admin half from modules/itemshop/*.
 *
 * Items are paid for in credits and delivered in game: a purchase writes a
 * `cp_redeemlog` row and an rAthena script hands the item over. The panel does
 * not touch a character's inventory, because the character may be online and
 * the map server would overwrite it.
 */
final class ItemShopController
{
    public function __construct(private readonly ItemShop $shop) {}

    /**
     * What is for sale.
     *
     * @throws ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:50'],
        ]);

        $query = $this->shop->catalogue();

        if (($category = $request->integer('category')) > 0) {
            $query->where('category', $category);
        }

        $list = new ListQuery(
            sortable: ['cost' => 'cost', 'added' => 'create_date', 'item' => 'nameid'],
            defaultSort: 'added',
        );

        $page = $list->paginate($query, $request);

        $names = $this->shop->itemNames(
            array_map(fn (object $row): int => (int) $row->nameid, $page->items()),
        );

        /*
         * The name search is applied after resolving, because the names live
         * in the merged reference table rather than in `cp_itemshop`, and a
         * join across a derived table for a substring match would cost more
         * than filtering a page of twenty.
         */
        $search = mb_strtolower(trim((string) $request->string('name')));

        $items = [];

        foreach ($page->items() as $row) {
            $name = $names[(int) $row->nameid] ?? null;

            if ($search !== '' && ! str_contains(mb_strtolower((string) $name), $search)) {
                continue;
            }

            $items[] = [
                'id' => (int) $row->id,
                'item' => ['id' => (int) $row->nameid, 'name' => $name],
                'bundle_quantity' => (int) $row->quantity,
                'cost' => (int) $row->cost,
                'category' => $row->category === null ? null : (int) $row->category,
                'description' => $row->info === null ? null : (string) $row->info,
            ];
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'balance' => $this->shop->balance($this->account($request)),
                'categories' => (object) config('rathena_reference.shop_categories', []),
                'filtered_by_name' => $search !== '',
            ],
        ]);
    }

    /**
     * The cart.
     */
    public function cart(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $priced = $this->shop->pricedCart();

        return response()->json([
            'data' => $priced['lines'],
            'meta' => [
                'total' => $priced['total'],
                'balance' => $this->shop->balance($account),
                'affordable' => $priced['total'] <= $this->shop->balance($account),
                /*
                 * Items withdrawn from sale since they were added. Reported
                 * rather than silently dropped, so nobody wonders why their
                 * cart emptied itself.
                 */
                'withdrawn' => $priced['withdrawn'],
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function addToCart(Request $request): JsonResponse
    {
        $this->account($request);

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $this->shop->addToCart($validated['id'], (int) ($validated['quantity'] ?? 1));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->cart($request);
    }

    /**
     * @throws ValidationException
     */
    public function removeFromCart(Request $request): JsonResponse
    {
        $this->account($request);

        $validated = $request->validate(['id' => ['required', 'integer']]);

        $this->shop->removeFromCart($validated['id']);

        return $this->cart($request);
    }

    public function clearCart(Request $request): JsonResponse
    {
        $this->account($request);

        $this->shop->clearCart();

        return $this->cart($request);
    }

    /**
     * Buy what is in the cart.
     */
    public function checkout(Request $request): JsonResponse
    {
        $account = $this->account($request);

        try {
            $result = $this->shop->checkout($account);
        } catch (RuntimeException $e) {
            // An empty cart, or a balance that will not cover it. Both are
            // ordinary and need a message rather than an error page.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Your purchase is waiting to be collected in game.',
            'data' => $result,
        ]);
    }

    /**
     * Purchases waiting to be collected.
     *
     * @throws ValidationException
     */
    public function pending(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->shop->pending($account);

        if (! $request->boolean('all')) {
            $query->where('redeemed', 0);
        }

        $list = new ListQuery(
            sortable: ['purchased' => 'purchase_date', 'collected' => 'redemption_date'],
            defaultSort: 'purchased',
        );

        $page = $list->paginate($query, $request);

        $names = $this->shop->itemNames(
            array_map(fn (object $row): int => (int) $row->nameid, $page->items()),
        );

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'item' => [
                    'id' => (int) $row->nameid,
                    'name' => $names[(int) $row->nameid] ?? null,
                ],
                'quantity' => (int) $row->quantity,
                'cost' => (int) $row->cost,
                'collected' => (int) $row->redeemed !== 0,
                'purchased_at' => $this->iso($row->purchase_date),
                'collected_at' => $this->iso($row->redemption_date),
                'character_id' => $row->char_id === null ? null : (int) $row->char_id,
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'balance' => $this->shop->balance($account),
            ],
        ]);
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return $account;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
