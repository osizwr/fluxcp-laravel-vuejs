<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Models\Guild;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Castles, the siege schedule, and player shops.
 *
 * Ports the coverage for castle/index, woe/index, vending/index,
 * vending/viewshop, buyingstore/index and buyingstore/viewshop.
 */
final class WorldAndShopTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    /*
    |--------------------------------------------------------------------------
    | Castles
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function castles_list_their_owners(): void
    {
        $guild = Guild::factory()->named('Valkyrie')->state(['emblem_id' => 7])->create();

        DB::connection($this->charMap())->table('guild_castle')->insert([
            ['castle_id' => 0, 'guild_id' => $guild->guild_id, 'economy' => 50, 'defense' => 20],
        ]);

        $response = $this->getJson('/api/world/castles')->assertOk();

        $response->assertJsonPath('data.0.id', 0)
            ->assertJsonPath('data.0.name', 'Neuschwanstein')
            ->assertJsonPath('data.0.owner.name', 'Valkyrie')
            ->assertJsonPath('data.0.economy', 50)
            ->assertJsonPath('meta.held', 1);
    }

    #[Test]
    public function an_unowned_castle_is_listed_with_no_owner(): void
    {
        /*
         * guild_castle is only populated once a castle has been taken, so an
         * absent row and a guild_id of 0 both mean unowned. Both must render
         * as a castle nobody holds rather than as a missing entry.
         */
        DB::connection($this->charMap())->table('guild_castle')->insert([
            ['castle_id' => 1, 'guild_id' => 0],
        ]);

        $response = $this->getJson('/api/world/castles')->assertOk();

        $data = collect($response->json('data'))->keyBy('id');

        $this->assertNull($data[0]['owner'], 'A castle with no row at all is unowned.');
        $this->assertNull($data[1]['owner'], 'A castle owned by guild 0 is unowned.');
        $this->assertSame(0, $response->json('meta.held'));
    }

    #[Test]
    public function only_configured_castles_are_listed(): void
    {
        // Removing an entry is how an operator excludes the novice castles.
        config(['rathena_reference.castles' => [0 => 'First', 1 => 'Second']]);

        $this->getJson('/api/world/castles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.name', 'Second');
    }

    #[Test]
    public function castles_are_public(): void
    {
        $this->getJson('/api/world/castles')->assertOk();
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Siege schedule
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_siege_schedule_reports_windows_with_their_timezone(): void
    {
        $response = $this->getJson('/api/world/siege-schedule')->assertOk();

        $response->assertJsonStructure([
            'data' => [['key', 'name', 'timezone', 'server_time', 'active', 'next_start', 'windows']],
        ]);

        /*
         * The timezone is the point: "Saturday 20:00" without saying whose
         * 20:00 is why players turn up an hour out.
         */
        $this->assertNotSame('', $response->json('data.0.timezone'));
    }

    #[Test]
    public function siege_windows_name_their_days(): void
    {
        config(['rathena.groups.main.char_map_servers.main.woe_schedule' => [
            ['day' => 6, 'start' => '20:00', 'end_day' => 6, 'end' => '22:00'],
        ]]);
        $this->app->forgetInstance(ServerRegistry::class);

        $this->getJson('/api/world/siege-schedule')
            ->assertOk()
            ->assertJsonPath('data.0.windows.0.starts.day', 'Saturday')
            ->assertJsonPath('data.0.windows.0.starts.time', '20:00')
            ->assertJsonPath('data.0.windows.0.ends.day', 'Saturday');
    }

    #[Test]
    public function a_world_with_no_siege_reports_an_empty_schedule(): void
    {
        config(['rathena.groups.main.char_map_servers.main.woe_schedule' => []]);
        $this->app->forgetInstance(ServerRegistry::class);

        $this->getJson('/api/world/siege-schedule')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.windows')
            ->assertJsonPath('data.0.active', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Vending stalls
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function vending_stalls_are_listed_with_their_seller(): void
    {
        $seller = Character::factory()->forAccount(Account::factory()->create())
            ->named('Merchant')->create();

        DB::connection($this->charMap())->table('vendings')->insert([
            'char_id' => $seller->char_id, 'title' => 'Cheap potions',
            'map' => 'prontera', 'x' => 150, 'y' => 150, 'autotrade' => 1,
        ]);

        $this->getJson('/api/shops/vending')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Cheap potions')
            ->assertJsonPath('data.0.seller.name', 'Merchant')
            ->assertJsonPath('data.0.location.map', 'prontera')
            ->assertJsonPath('data.0.autotrade', true);
    }

    #[Test]
    public function a_stalls_stock_comes_through_the_sellers_cart(): void
    {
        /*
         * A vending stall sells out of the cart, so the item id, refine and
         * cards are on the cart row rather than on the shop line. Reading them
         * off the line would show every item as id 0.
         */
        $seller = Character::factory()->forAccount(Account::factory()->create())->create();

        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => 501, 'name_aegis' => 'Red_Potion', 'name_english' => 'Red Potion', 'type' => 'healing',
        ]);

        $cartId = DB::connection($this->charMap())->table('cart_inventory')->insertGetId([
            'char_id' => $seller->char_id, 'nameid' => 501, 'amount' => 10, 'refine' => 4, 'identify' => 1,
        ]);

        $shopId = DB::connection($this->charMap())->table('vendings')->insertGetId([
            'char_id' => $seller->char_id, 'title' => 'Potions', 'map' => 'prontera', 'x' => 1, 'y' => 1,
        ]);

        DB::connection($this->charMap())->table('vending_items')->insert([
            'vending_id' => $shopId, 'index' => 0, 'cartinventory_id' => $cartId,
            'amount' => 10, 'price' => 500,
        ]);

        $this->getJson("/api/shops/vending/{$shopId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.item.id', 501)
            ->assertJsonPath('data.items.0.item.name', 'Red Potion')
            ->assertJsonPath('data.items.0.price', 500)
            ->assertJsonPath('data.items.0.refine', 4);
    }

    #[Test]
    public function a_stall_selling_a_custom_item_names_it(): void
    {
        // The merge again: without it a custom item shows as a bare id.
        $seller = Character::factory()->forAccount(Account::factory()->create())->create();

        DB::connection($this->charMap())->table('item_db2_re')->insert([
            'id' => 30001, 'name_aegis' => 'Custom_Blade', 'name_english' => 'Custom Blade', 'type' => 'weapon',
        ]);

        $cartId = DB::connection($this->charMap())->table('cart_inventory')->insertGetId([
            'char_id' => $seller->char_id, 'nameid' => 30001, 'amount' => 1,
        ]);

        $shopId = DB::connection($this->charMap())->table('vendings')->insertGetId([
            'char_id' => $seller->char_id, 'title' => 'Rare', 'map' => 'prontera', 'x' => 1, 'y' => 1,
        ]);

        DB::connection($this->charMap())->table('vending_items')->insert([
            'vending_id' => $shopId, 'index' => 0, 'cartinventory_id' => $cartId,
            'amount' => 1, 'price' => 1000000,
        ]);

        $this->getJson("/api/shops/vending/{$shopId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.item.name', 'Custom Blade');
    }

    #[Test]
    public function stalls_can_be_searched_by_title_and_map(): void
    {
        $seller = Character::factory()->forAccount(Account::factory()->create())->create();

        DB::connection($this->charMap())->table('vendings')->insert([
            ['char_id' => $seller->char_id, 'title' => 'Cheap potions', 'map' => 'prontera', 'x' => 1, 'y' => 1],
            ['char_id' => $seller->char_id, 'title' => 'Rare cards', 'map' => 'geffen', 'x' => 1, 'y' => 1],
        ]);

        $this->getJson('/api/shops/vending?title=potions')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Cheap potions');

        $this->getJson('/api/shops/vending?map=geffen')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Rare cards');
    }

    /*
    |--------------------------------------------------------------------------
    | Buying stores
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function buying_stores_read_their_items_directly(): void
    {
        /*
         * A buying store holds no stock, so its items are on the line itself
         * with no cart to join through.
         */
        $buyer = Character::factory()->forAccount(Account::factory()->create())
            ->named('Collector')->create();

        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => 4001, 'name_aegis' => 'Poring_Card', 'name_english' => 'Poring Card', 'type' => 'card',
        ]);

        $shopId = DB::connection($this->charMap())->table('buyingstores')->insertGetId([
            'char_id' => $buyer->char_id, 'title' => 'Buying cards',
            'map' => 'prontera', 'x' => 1, 'y' => 1, 'limit' => 1000000,
        ]);

        DB::connection($this->charMap())->table('buyingstore_items')->insert([
            'buyingstore_id' => $shopId, 'index' => 0, 'nameid' => 4001, 'amount' => 5, 'price' => 20000,
        ]);

        $this->getJson("/api/shops/buying/{$shopId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Buying cards')
            ->assertJsonPath('data.seller.name', 'Collector')
            ->assertJsonPath('data.items.0.item.name', 'Poring Card')
            ->assertJsonPath('data.items.0.price', 20000);
    }

    #[Test]
    public function the_two_kinds_of_shop_do_not_mix(): void
    {
        $character = Character::factory()->forAccount(Account::factory()->create())->create();

        DB::connection($this->charMap())->table('vendings')->insert([
            'char_id' => $character->char_id, 'title' => 'Selling', 'map' => 'prontera', 'x' => 1, 'y' => 1,
        ]);
        DB::connection($this->charMap())->table('buyingstores')->insert([
            'char_id' => $character->char_id, 'title' => 'Buying', 'map' => 'prontera', 'x' => 1, 'y' => 1,
        ]);

        $this->getJson('/api/shops/vending')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Selling');

        $this->getJson('/api/shops/buying')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Buying');
    }

    #[Test]
    public function an_unknown_shop_kind_is_a_404(): void
    {
        $this->getJson('/api/shops/auctions')->assertNotFound();
    }

    #[Test]
    public function a_closed_shop_is_a_404(): void
    {
        // Shops vanish from the table the moment the player closes them.
        $this->getJson('/api/shops/vending/999999')->assertNotFound();
    }

    #[Test]
    public function shops_are_public(): void
    {
        $this->getJson('/api/shops/vending')->assertOk();
        $this->getJson('/api/shops/buying')->assertOk();
        $this->assertGuest();
    }
}
