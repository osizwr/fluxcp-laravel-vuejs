<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Account;
use App\Services\Rathena\ReferenceTables;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * The credit shop.
 *
 * Ports Flux_ItemShop and Flux_ItemShop_Cart, and the bodies of
 * modules/purchase/*.
 *
 * Items are bought with credits held in `cp_credits` and delivered in game:
 * a purchase writes a `cp_redeemlog` row with `redeemed = 0`, and an rAthena
 * script hands the item over and flips the flag. The panel never touches a
 * character's inventory directly, which is deliberate -- the character may be
 * online and the map server would overwrite it.
 *
 * ---------------------------------------------------------------------------
 * Spending credits is the part that has to be right
 * ---------------------------------------------------------------------------
 *
 * Credits are the server's currency and somebody paid money for them. A
 * checkout that can be made to run twice, or to spend a balance that is no
 * longer there, is a way to mint items.
 *
 * So the deduction is a single conditional UPDATE inside a transaction:
 *
 *     UPDATE cp_credits SET balance = balance - ? WHERE account_id = ? AND balance >= ?
 *
 * The database decides whether the balance covers it, atomically, rather than
 * this code reading the balance and then writing a new one -- which is the
 * shape that loses to two requests arriving together. If the update affects no
 * rows, the balance moved underneath us and the whole checkout rolls back.
 */
final readonly class ItemShop
{
    /** Where the cart lives between requests. */
    private const CART_KEY = 'itemshop.cart';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
        private ReferenceTables $reference,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * Items for sale, as a query the caller filters and paginates.
     */
    public function catalogue(): Builder
    {
        return $this->connection()
            ->table('cp_itemshop')
            ->select('id', 'nameid', 'category', 'quantity', 'cost', 'info', 'use_existing', 'create_date');
    }

    public function findItem(int $id): ?object
    {
        return $this->connection()->table('cp_itemshop')->where('id', $id)->first();
    }

    /**
     * Item names for a set of shop rows, from the merged reference table, so a
     * server's own custom item is named rather than shown as a bare id (D6).
     *
     * @param  list<int>  $nameIds
     * @return array<int, string>
     */
    public function itemNames(array $nameIds): array
    {
        if ($nameIds === []) {
            return [];
        }

        $key = $this->reference->keyColumn('items');

        return $this->reference->items()
            ->whereIn($key, $nameIds)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->{$key} => (string) ($row->name_english ?? ''),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | The cart
    |--------------------------------------------------------------------------
    */

    /**
     * The cart as stored: shop item id => quantity.
     *
     * Kept in the session rather than a table, as the legacy did. A cart is
     * not worth a row until it becomes a purchase, and a session cart cannot
     * be left behind by somebody who never returns.
     *
     * @return array<int, int>
     */
    public function cart(): array
    {
        $cart = session(self::CART_KEY, []);

        return is_array($cart) ? $cart : [];
    }

    /**
     * Put an item in the cart.
     *
     * @return array<int, int> The cart as it now stands.
     */
    public function addToCart(int $shopItemId, int $quantity): array
    {
        $item = $this->findItem($shopItemId);

        if ($item === null) {
            throw new RuntimeException('That item is no longer for sale.');
        }

        $max = max(1, (int) config('panel.item_shop.max_quantity', 99));
        $cart = $this->cart();

        $quantity = max(1, min($quantity, $max));

        $cart[$shopItemId] = min(($cart[$shopItemId] ?? 0) + $quantity, $max);

        session([self::CART_KEY => $cart]);

        return $cart;
    }

    /**
     * @return array<int, int>
     */
    public function removeFromCart(int $shopItemId): array
    {
        $cart = $this->cart();

        unset($cart[$shopItemId]);

        session([self::CART_KEY => $cart]);

        return $cart;
    }

    public function clearCart(): void
    {
        session()->forget(self::CART_KEY);
    }

    /**
     * The cart with its items resolved and priced.
     *
     * Rows whose shop entry has since been withdrawn are dropped rather than
     * priced at zero, and reported, so somebody is not quietly charged for
     * something that is no longer on sale -- or quietly given it free.
     *
     * @return array{lines: list<array<string, mixed>>, total: int, withdrawn: list<int>}
     */
    public function pricedCart(): array
    {
        $cart = $this->cart();

        if ($cart === []) {
            return ['lines' => [], 'total' => 0, 'withdrawn' => []];
        }

        $rows = $this->connection()
            ->table('cp_itemshop')
            ->whereIn('id', array_keys($cart))
            ->get()
            ->keyBy('id');

        $names = $this->itemNames($rows->pluck('nameid')->map(fn ($id): int => (int) $id)->all());

        $lines = [];
        $withdrawn = [];
        $total = 0;

        foreach ($cart as $shopItemId => $quantity) {
            $row = $rows[$shopItemId] ?? null;

            if ($row === null) {
                $withdrawn[] = (int) $shopItemId;

                continue;
            }

            $cost = (int) $row->cost * (int) $quantity;
            $total += $cost;

            $lines[] = [
                'id' => (int) $row->id,
                'item' => [
                    'id' => (int) $row->nameid,
                    'name' => $names[(int) $row->nameid] ?? null,
                ],
                'bundle_quantity' => (int) $row->quantity,
                'quantity' => (int) $quantity,
                'unit_cost' => (int) $row->cost,
                'cost' => $cost,
            ];
        }

        return ['lines' => $lines, 'total' => $total, 'withdrawn' => $withdrawn];
    }

    /*
    |--------------------------------------------------------------------------
    | Buying
    |--------------------------------------------------------------------------
    */

    public function balance(Account $account): int
    {
        return (int) ($this->connection()
            ->table('cp_credits')
            ->where('account_id', $account->account_id)
            ->value('balance') ?? 0);
    }

    /**
     * Buy everything in the cart.
     *
     * @return array{purchased: int, spent: int, balance: int}
     *
     * @throws RuntimeException When the cart is empty or the balance will not cover it.
     */
    public function checkout(Account $account): array
    {
        $priced = $this->pricedCart();

        if ($priced['lines'] === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        $total = $priced['total'];
        $connection = $this->connection();

        $result = $connection->transaction(function () use ($connection, $account, $priced, $total): array {
            $before = (int) ($connection->table('cp_credits')
                ->where('account_id', $account->account_id)
                // Row lock, so a second checkout arriving at the same moment
                // waits rather than reading the same balance.
                ->lockForUpdate()
                ->value('balance') ?? 0);

            if ($before < $total) {
                throw new RuntimeException('You do not have enough credits for that.');
            }

            /*
             * Conditional, so the database decides whether the balance covers
             * it. Reading then writing is the shape that loses to two requests
             * arriving together.
             */
            $deducted = $connection->table('cp_credits')
                ->where('account_id', $account->account_id)
                ->where('balance', '>=', $total)
                ->update(['balance' => $connection->raw("balance - {$total}")]);

            if ($deducted === 0) {
                throw new RuntimeException('Your balance changed. Please try again.');
            }

            $after = $before - $total;
            $now = now();

            foreach ($priced['lines'] as $line) {
                /*
                 * One row per unit bought, because that is how the in-game
                 * redemption script consumes them: it hands over one bundle
                 * and flips one row.
                 */
                for ($i = 0; $i < $line['quantity']; $i++) {
                    $connection->table('cp_redeemlog')->insert([
                        'nameid' => $line['item']['id'],
                        'quantity' => $line['bundle_quantity'],
                        'cost' => $line['unit_cost'],
                        'account_id' => $account->account_id,
                        'char_id' => null,
                        'redeemed' => 0,
                        'purchase_date' => $now,
                        'credits_before' => $before,
                        'credits_after' => $after,
                    ]);
                }
            }

            return [
                'purchased' => array_sum(array_column($priced['lines'], 'quantity')),
                'spent' => $total,
                'balance' => $after,
            ];
        });

        $this->clearCart();

        return $result;
    }

    /**
     * Purchases waiting to be collected in game.
     */
    public function pending(Account $account): Builder
    {
        return $this->connection()
            ->table('cp_redeemlog')
            ->select('id', 'nameid', 'quantity', 'cost', 'redeemed', 'purchase_date', 'redemption_date', 'char_id')
            ->where('account_id', $account->account_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Administration
    |--------------------------------------------------------------------------
    */

    public function addShopItem(int $nameId, int $cost, int $quantity, ?int $category, ?string $info, bool $useExisting): int
    {
        return (int) $this->connection()->table('cp_itemshop')->insertGetId([
            'nameid' => $nameId,
            'cost' => $cost,
            'quantity' => $quantity,
            'category' => $category,
            'info' => $info,
            'use_existing' => $useExisting ? 1 : 0,
            'create_date' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateShopItem(int $id, array $changes): bool
    {
        return $this->connection()->table('cp_itemshop')->where('id', $id)->update($changes) > 0;
    }

    /**
     * Withdraw an item from sale.
     *
     * The `cp_redeemlog` rows stay: they are what somebody already bought, and
     * deleting them would take away a purchase that has not been collected.
     */
    public function removeShopItem(int $id): bool
    {
        return $this->connection()->table('cp_itemshop')->where('id', $id)->delete() > 0;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->servers->current()->loginConnection());
    }
}
