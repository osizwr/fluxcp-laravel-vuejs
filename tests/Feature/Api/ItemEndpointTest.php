<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The item database endpoints.
 *
 * Ports the coverage for modules/item/index.php, view.php and iteminfo.php.
 */
final class ItemEndpointTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(int $id, array $attributes = [], string $table = 'item_db_re'): void
    {
        DB::connection($this->charMapConnection())->table($table)->insert([
            'id' => $id,
            'name_aegis' => "Item{$id}",
            'name_english' => "Item {$id}",
            'type' => 'etc',
            'price_buy' => 100,
            'price_sell' => 50,
            'weight' => 10,
            'slots' => 0,
            ...$attributes,
        ]);
    }

    private function charMapConnection(): string
    {
        return $this->app->make(ServerRegistry::class)
            ->currentCharMapServer()->connectionName();
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_listing_is_public(): void
    {
        $this->item(501, ['name_english' => 'Red Potion']);

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Red Potion');
    }

    #[Test]
    public function custom_items_appear_alongside_stock_ones(): void
    {
        /*
         * The reason the merge exists. A panel reading item_db_re alone shows
         * only the first of these, which looks right until somebody asks why
         * their server's custom sword is missing.
         */
        $this->item(501, ['name_english' => 'Red Potion']);
        $this->item(30001, ['name_english' => 'Custom Blade'], 'item_db2_re');

        $response = $this->getJson('/api/items')->assertOk();

        $this->assertSame(['Red Potion', 'Custom Blade'], $response->json('data.*.name'));
        $this->assertFalse($response->json('data.0.is_custom'));
        $this->assertTrue($response->json('data.1.is_custom'));
    }

    #[Test]
    public function an_overridden_item_shows_its_custom_stats(): void
    {
        $this->item(501, ['name_english' => 'Red Potion', 'price_buy' => 50]);
        $this->item(501, ['name_english' => 'Red Potion', 'price_buy' => 9999], 'item_db2_re');

        $response = $this->getJson('/api/items')->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(9999, $response->json('data.0.price.buy'));
        $this->assertTrue($response->json('data.0.is_custom'));
    }

    #[Test]
    public function items_can_be_searched_by_name(): void
    {
        $this->item(501, ['name_english' => 'Red Potion']);
        $this->item(502, ['name_english' => 'Orange Potion']);
        $this->item(1201, ['name_english' => 'Knife']);

        $this->getJson('/api/items?name=Potion')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function the_aegis_name_is_searched_too(): void
    {
        // Scripts refer to items by the Aegis name, so somebody pasting one
        // from a script should find the item.
        $this->item(501, ['name_english' => 'Red Potion', 'name_aegis' => 'Red_Potion']);

        $this->getJson('/api/items?name=Red_Potion')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_wildcard_in_the_search_term_is_escaped(): void
    {
        /*
         * Without escaping, searching for "%" matches every item, and a term
         * containing "_" quietly matches any single character.
         */
        $this->item(501, ['name_english' => 'Red Potion']);
        $this->item(502, ['name_english' => '100% Juice']);

        $this->getJson('/api/items?name=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '100% Juice');
    }

    #[Test]
    public function items_can_be_filtered_by_type(): void
    {
        $this->item(501, ['type' => 'healing']);
        $this->item(1201, ['type' => 'weapon']);

        $this->getJson('/api/items?type=weapon')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 1201);
    }

    #[Test]
    public function an_unknown_type_is_refused_rather_than_ignored(): void
    {
        $this->item(501);

        $this->getJson('/api/items?type=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filter');
    }

    #[Test]
    public function items_can_be_filtered_by_equip_location(): void
    {
        $this->item(1201, ['name_english' => 'Knife', 'location_right_hand' => 1]);
        $this->item(2301, ['name_english' => 'Cotton Shirt', 'location_armor' => 1]);

        $this->getJson('/api/items?location=location_armor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cotton Shirt');
    }

    #[Test]
    public function a_location_the_schema_does_not_have_matches_nothing(): void
    {
        /*
         * A pre-renewal schema has fewer attribute columns. Asking for one it
         * lacks must be an empty result, not an SQL error about a column the
         * person never named.
         */
        $this->item(501);

        $this->getJson('/api/items?location=location_shadow_armor')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function numeric_filters_accept_named_operators(): void
    {
        $this->item(501, ['price_buy' => 50]);
        $this->item(502, ['price_buy' => 500]);
        $this->item(503, ['price_buy' => 5000]);

        $this->getJson('/api/items?price_buy=500&price_buy_op=gte')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/items?price_buy=500&price_buy_op=lt')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_unknown_operator_is_refused(): void
    {
        $this->item(501);

        $this->getJson('/api/items?price_buy=100&price_buy_op=;DROP')
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_buy_op');
    }

    #[Test]
    public function items_can_be_limited_to_custom_entries(): void
    {
        $this->item(501, ['name_english' => 'Red Potion']);
        $this->item(30001, ['name_english' => 'Custom Blade'], 'item_db2_re');

        $this->getJson('/api/items?origin=custom')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Custom Blade');

        $this->getJson('/api/items?origin=stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Red Potion');
    }

    /*
    |--------------------------------------------------------------------------
    | Sorting and paging
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_listing_can_be_sorted(): void
    {
        $this->item(501, ['name_english' => 'Zeny Bag', 'price_buy' => 10]);
        $this->item(502, ['name_english' => 'Apple', 'price_buy' => 90]);

        $this->getJson('/api/items?sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Apple');

        $this->getJson('/api/items?sort=price_buy&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Apple');
    }

    #[Test]
    public function an_unlisted_sort_column_is_refused(): void
    {
        $this->item(501);

        $this->getJson('/api/items?sort=script')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    #[Test]
    public function the_listing_is_paginated_and_capped(): void
    {
        foreach (range(1, 30) as $n) {
            $this->item(500 + $n);
        }

        $this->getJson('/api/items?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 30);

        config()->set('panel.pagination.max_per_page', 10);

        $this->getJson('/api/items?per_page=1000')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Detail
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_item_can_be_viewed(): void
    {
        $this->item(1201, [
            'name_english' => 'Knife',
            'type' => 'weapon',
            'attack' => 17,
            'slots' => 3,
            'weapon_level' => 1,
            'location_right_hand' => 1,
            'job_all' => 1,
            'class_all' => 1,
            'trade_nodrop' => 1,
            'script' => 'bonus bStr,1;',
        ]);

        $this->getJson('/api/items/1201')
            ->assertOk()
            ->assertJsonPath('data.name', 'Knife')
            ->assertJsonPath('data.type', 'Weapon')
            ->assertJsonPath('data.attack', 17)
            ->assertJsonPath('data.slots', 3)
            ->assertJsonPath('data.equip_locations.0', 'Main Hand')
            ->assertJsonPath('data.jobs.0', 'All jobs')
            ->assertJsonPath('data.classes.0', 'All classes')
            ->assertJsonPath('data.trade_restrictions.0', "Can't be dropped")
            ->assertJsonPath('data.script', 'bonus bStr,1;');
    }

    #[Test]
    public function job_all_collapses_instead_of_listing_every_job(): void
    {
        /*
         * rAthena sets job_all *instead of* the individual columns, so listing
         * twenty-six entries would be both wrong and unreadable.
         */
        $this->item(1201, ['job_all' => 1, 'job_swordman' => 1]);

        $this->getJson('/api/items/1201')
            ->assertOk()
            ->assertJsonCount(1, 'data.jobs')
            ->assertJsonPath('data.jobs.0', 'All jobs');
    }

    #[Test]
    public function individual_jobs_are_listed_when_job_all_is_not_set(): void
    {
        $this->item(1201, ['job_swordman' => 1, 'job_merchant' => 1]);

        $response = $this->getJson('/api/items/1201')->assertOk();

        $this->assertSame(['Swordman', 'Merchant'], $response->json('data.jobs'));
    }

    #[Test]
    public function the_script_is_omitted_from_the_listing(): void
    {
        /*
         * Item scripts are rAthena source and can be long. Sending them for
         * every row of every page would dominate the response.
         */
        $this->item(1201, ['script' => 'bonus bStr,1;']);

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonMissingPath('data.0.script');
    }

    #[Test]
    public function an_unknown_item_is_a_404(): void
    {
        $this->getJson('/api/items/999999')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Vocabulary
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_filter_vocabulary_is_staff_only(): void
    {
        // ADMIN in the legacy access map, preserved.
        $this->getJson('/api/items/vocabulary')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/items/vocabulary')
            ->assertStatus(403);
    }

    #[Test]
    public function the_filter_vocabulary_lists_the_configured_terms(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/items/vocabulary')
            ->assertOk()
            ->assertJsonPath('data.types.weapon', 'Weapon')
            ->assertJsonPath('data.locations.location_armor', 'Armor')
            ->assertJsonPath('data.operators.0', 'eq');
    }
}
