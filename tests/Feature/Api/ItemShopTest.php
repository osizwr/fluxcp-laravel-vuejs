<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The credit shop.
 *
 * Ports the coverage for purchase/* and itemshop/*.
 *
 * The checkout tests are the ones that matter. Credits are the server's
 * currency and somebody paid money for them, so a checkout that can be made to
 * run twice, or to spend a balance that is no longer there, is a way to mint
 * items.
 */
final class ItemShopTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    private function sellable(int $nameId = 501, int $cost = 100, int $bundle = 1): int
    {
        DB::connection($this->charMap())->table('item_db_re')->insertOrIgnore([
            'id' => $nameId, 'name_aegis' => "Item{$nameId}", 'name_english' => 'Red Potion',
        ]);

        return (int) DB::connection($this->loginConnection())->table('cp_itemshop')->insertGetId([
            'nameid' => $nameId, 'cost' => $cost, 'quantity' => $bundle,
            'category' => 1, 'create_date' => now(),
        ]);
    }

    private function withCredits(int $balance): Account
    {
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_credits')->insert([
            'account_id' => $account->account_id, 'balance' => $balance,
        ]);

        return $account;
    }

    private function balanceOf(Account $account): int
    {
        return (int) DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)->value('balance');
    }

    /*
    |--------------------------------------------------------------------------
    | Browsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_shop_lists_items_with_their_names_and_the_viewers_balance(): void
    {
        $this->sellable();
        $account = $this->withCredits(500);

        $this->actingAs($account)
            ->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('data.0.item.name', 'Red Potion')
            ->assertJsonPath('data.0.cost', 100)
            ->assertJsonPath('meta.balance', 500);
    }

    #[Test]
    public function a_custom_item_on_sale_is_named(): void
    {
        // The merge again: without it a custom item shows as a bare id.
        DB::connection($this->charMap())->table('item_db2_re')->insert([
            'id' => 30001, 'name_aegis' => 'Custom', 'name_english' => 'Custom Blade',
        ]);

        DB::connection($this->loginConnection())->table('cp_itemshop')->insert([
            'nameid' => 30001, 'cost' => 1000, 'quantity' => 1, 'create_date' => now(),
        ]);

        $this->actingAs($this->withCredits(0))
            ->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('data.0.item.name', 'Custom Blade');
    }

    #[Test]
    public function the_shop_needs_a_session(): void
    {
        $this->getJson('/api/shop')->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | The cart
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function items_can_be_added_removed_and_cleared(): void
    {
        $id = $this->sellable(cost: 100);
        $account = $this->withCredits(500);

        $this->actingAs($account)
            ->postJson('/api/shop/cart', ['id' => $id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 2)
            ->assertJsonPath('meta.total', 200);

        $this->actingAs($account)
            ->deleteJson('/api/shop/cart/item', ['id' => $id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();
        $this->actingAs($account)->deleteJson('/api/shop/cart')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_quantity_beyond_the_configured_maximum_is_capped(): void
    {
        config()->set('panel.item_shop.max_quantity', 5);

        $id = $this->sellable();

        $this->actingAs($this->withCredits(100000))
            ->postJson('/api/shop/cart', ['id' => $id, 'quantity' => 999])
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 5);
    }

    #[Test]
    public function an_item_withdrawn_from_sale_is_reported_rather_than_silently_dropped(): void
    {
        /*
         * Otherwise somebody wonders why their cart emptied itself — or worse,
         * is charged for something no longer on sale.
         */
        $id = $this->sellable();
        $account = $this->withCredits(500);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();

        DB::connection($this->loginConnection())->table('cp_itemshop')->where('id', $id)->delete();

        $this->actingAs($account)
            ->getJson('/api/shop/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.withdrawn.0', $id)
            ->assertJsonPath('meta.total', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function checking_out_deducts_credits_and_queues_the_items(): void
    {
        $id = $this->sellable(cost: 100, bundle: 3);
        $account = $this->withCredits(500);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id, 'quantity' => 2])->assertOk();

        $this->actingAs($account)
            ->postJson('/api/shop/checkout')
            ->assertOk()
            ->assertJsonPath('data.spent', 200)
            ->assertJsonPath('data.balance', 300);

        $this->assertSame(300, $this->balanceOf($account));

        /*
         * One row per unit bought, because the in-game redemption script hands
         * over one bundle and flips one row.
         */
        $rows = DB::connection($this->loginConnection())->table('cp_redeemlog')
            ->where('account_id', $account->account_id)->get();

        $this->assertCount(2, $rows);
        $this->assertSame(3, (int) $rows[0]->quantity, 'Each row carries the bundle size.');
        $this->assertSame(0, (int) $rows[0]->redeemed, 'Nothing is delivered by the panel.');
    }

    #[Test]
    public function the_cart_is_emptied_after_a_successful_checkout(): void
    {
        $id = $this->sellable(cost: 100);
        $account = $this->withCredits(500);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();
        $this->actingAs($account)->postJson('/api/shop/checkout')->assertOk();

        $this->actingAs($account)->getJson('/api/shop/cart')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_balance_that_does_not_cover_the_cart_is_refused(): void
    {
        $id = $this->sellable(cost: 1000);
        $account = $this->withCredits(100);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();

        $this->actingAs($account)->postJson('/api/shop/checkout')->assertStatus(422);

        $this->assertSame(100, $this->balanceOf($account), 'Nothing is deducted on a refusal.');
        $this->assertSame(
            0,
            DB::connection($this->loginConnection())->table('cp_redeemlog')->count(),
            'And nothing is queued.',
        );
    }

    #[Test]
    public function a_second_checkout_cannot_spend_the_same_credits(): void
    {
        /*
         * The reason the deduction is a conditional UPDATE inside a
         * transaction rather than a read followed by a write. Replaying the
         * request must not mint a second purchase.
         */
        $id = $this->sellable(cost: 400);
        $account = $this->withCredits(500);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();
        $this->actingAs($account)->postJson('/api/shop/checkout')->assertOk();

        // The cart is empty now, so a replay has nothing to buy.
        $this->actingAs($account)->postJson('/api/shop/checkout')->assertStatus(422);

        $this->assertSame(100, $this->balanceOf($account));
        $this->assertSame(1, DB::connection($this->loginConnection())->table('cp_redeemlog')->count());
    }

    #[Test]
    public function a_balance_spent_elsewhere_between_adding_and_checking_out_is_caught(): void
    {
        $id = $this->sellable(cost: 400);
        $account = $this->withCredits(500);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();

        // Spent in game, or by another request, after the cart was filled.
        DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)->update(['balance' => 50]);

        $this->actingAs($account)->postJson('/api/shop/checkout')->assertStatus(422);

        $this->assertSame(50, $this->balanceOf($account));
    }

    #[Test]
    public function an_empty_cart_cannot_be_checked_out(): void
    {
        $this->actingAs($this->withCredits(500))
            ->postJson('/api/shop/checkout')
            ->assertStatus(422);
    }

    #[Test]
    public function an_account_with_no_credit_row_has_a_zero_balance(): void
    {
        // The row is created on first donation, not with the account.
        $id = $this->sellable(cost: 1);
        $account = Account::factory()->create();

        $this->actingAs($account)->getJson('/api/shop')->assertOk()->assertJsonPath('meta.balance', 0);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();
        $this->actingAs($account)->postJson('/api/shop/checkout')->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Pending redemption
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function pending_purchases_are_listed_until_collected(): void
    {
        $id = $this->sellable(cost: 10);
        $account = $this->withCredits(100);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();
        $this->actingAs($account)->postJson('/api/shop/checkout')->assertOk();

        $this->actingAs($account)
            ->getJson('/api/shop/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.collected', false)
            ->assertJsonPath('data.0.item.name', 'Red Potion');

        // Collected in game by the rAthena script.
        DB::connection($this->loginConnection())->table('cp_redeemlog')
            ->where('account_id', $account->account_id)
            ->update(['redeemed' => 1, 'redemption_date' => now(), 'char_id' => 150001]);

        $this->actingAs($account)->getJson('/api/shop/pending')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($account)->getJson('/api/shop/pending?all=1')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function another_accounts_purchases_are_not_visible(): void
    {
        $theirs = $this->withCredits(0);

        DB::connection($this->loginConnection())->table('cp_redeemlog')->insert([
            'nameid' => 501, 'quantity' => 1, 'cost' => 10,
            'account_id' => $theirs->account_id, 'redeemed' => 0, 'purchase_date' => now(),
        ]);

        $this->actingAs($this->withCredits(0))
            ->getJson('/api/shop/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Administration
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function listing_an_item_needs_its_ability(): void
    {
        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/shop', ['nameid' => 501, 'cost' => 10, 'quantity' => 1])
            ->assertStatus(403);

        $this->actingAs(Account::factory()->administrator()->create())
            ->postJson('/api/admin/shop', ['nameid' => 501, 'cost' => 10, 'quantity' => 1])
            ->assertCreated();

        $this->assertDatabaseHas('cp_itemshop', ['nameid' => 501], $this->loginConnection());
    }

    #[Test]
    public function the_abilities_are_separate_from_the_route_level(): void
    {
        /*
         * So an operator can let an administrator edit prices without letting
         * them withdraw items, which is the distinction the legacy access map
         * drew with AddShopItem, EditShopItem and DeleteShopItem.
         */
        Gate::define('DeleteShopItem', static fn (): bool => false);

        $id = $this->sellable();

        $this->actingAs(Account::factory()->administrator()->create())
            ->deleteJson("/api/admin/shop/{$id}")
            ->assertStatus(403);
    }

    #[Test]
    public function a_price_beyond_the_configured_cap_is_refused(): void
    {
        config()->set('panel.item_shop.max_cost', 1000);

        $this->actingAs(Account::factory()->administrator()->create())
            ->postJson('/api/admin/shop', ['nameid' => 501, 'cost' => 99999, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cost');
    }

    #[Test]
    public function withdrawing_an_item_leaves_purchases_alone(): void
    {
        /*
         * cp_redeemlog rows are what somebody already bought. Deleting them
         * would take away a purchase that has not been collected.
         */
        $id = $this->sellable(cost: 10);
        $account = $this->withCredits(100);

        $this->actingAs($account)->postJson('/api/shop/cart', ['id' => $id])->assertOk();
        $this->actingAs($account)->postJson('/api/shop/checkout')->assertOk();

        $this->actingAs(Account::factory()->administrator()->create())
            ->deleteJson("/api/admin/shop/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('cp_itemshop', ['id' => $id], $this->loginConnection());
        $this->assertSame(1, DB::connection($this->loginConnection())->table('cp_redeemlog')->count());

        $this->actingAs($account)->getJson('/api/shop/pending')->assertOk()->assertJsonCount(1, 'data');
    }
}
