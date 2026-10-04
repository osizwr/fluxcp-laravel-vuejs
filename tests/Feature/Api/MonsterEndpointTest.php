<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The monster database endpoints.
 *
 * Ports the coverage for modules/monster/index.php and view.php.
 *
 * The test schema uses rAthena's capitalised column spelling (`ID`, `iName`,
 * `LV`) on purpose. A panel that assumes the lowercase names returns an empty
 * listing against it, so these tests fail rather than pass vacuously if the
 * column resolution is removed.
 */
final class MonsterEndpointTest extends TestCase
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
    private function monster(int $id, array $attributes = [], string $table = 'mob_db_re'): void
    {
        DB::connection($this->charMapConnection())->table($table)->insert([
            'ID' => $id,
            'Sprite' => "MOB{$id}",
            'kName' => "Monster {$id}",
            'iName' => "Monster {$id}",
            'LV' => 10,
            'HP' => 100,
            'EXP' => 50,
            'JEXP' => 25,
            ...$attributes,
        ]);
    }

    private function charMapConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    #[Test]
    public function the_listing_is_public_and_reads_the_capitalised_columns(): void
    {
        $this->monster(1002, ['iName' => 'Poring', 'LV' => 1, 'HP' => 50]);

        $this->getJson('/api/monsters')
            ->assertOk()
            ->assertJsonPath('data.0.id', 1002)
            ->assertJsonPath('data.0.name', 'Poring')
            ->assertJsonPath('data.0.level', 1)
            ->assertJsonPath('data.0.hp', 50);
    }

    #[Test]
    public function custom_monsters_are_merged_in(): void
    {
        $this->monster(1002, ['iName' => 'Poring']);
        $this->monster(3999, ['iName' => 'Server Boss'], 'mob_db2_re');

        $response = $this->getJson('/api/monsters')->assertOk();

        $this->assertSame(['Poring', 'Server Boss'], $response->json('data.*.name'));
        $this->assertTrue($response->json('data.1.is_custom'));
    }

    #[Test]
    public function an_overridden_monster_shows_its_custom_stats(): void
    {
        $this->monster(1002, ['iName' => 'Poring', 'LV' => 1, 'HP' => 50]);
        $this->monster(1002, ['iName' => 'Angry Poring', 'LV' => 99, 'HP' => 99999], 'mob_db2_re');

        $this->getJson('/api/monsters')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Angry Poring')
            ->assertJsonPath('data.0.level', 99);
    }

    #[Test]
    public function monsters_can_be_searched_by_name(): void
    {
        $this->monster(1002, ['iName' => 'Poring']);
        $this->monster(1113, ['iName' => 'Drops']);

        $this->getJson('/api/monsters?name=Por')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Poring');
    }

    #[Test]
    public function monsters_can_be_filtered_by_level_range(): void
    {
        $this->monster(1002, ['iName' => 'Poring', 'LV' => 1]);
        $this->monster(1086, ['iName' => 'Golden Thief Bug', 'LV' => 65]);
        $this->monster(1157, ['iName' => 'Pharaoh', 'LV' => 93]);

        $this->getJson('/api/monsters?level_min=60&level_max=90')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Golden Thief Bug');
    }

    #[Test]
    public function mvps_can_be_singled_out(): void
    {
        // rAthena identifies an MVP by it carrying MVP experience.
        $this->monster(1002, ['iName' => 'Poring', 'MEXP' => 0]);
        $this->monster(1086, ['iName' => 'Golden Thief Bug', 'MEXP' => 200]);

        $this->getJson('/api/monsters?mvp=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Golden Thief Bug')
            ->assertJsonPath('data.0.is_mvp', true);
    }

    #[Test]
    public function the_listing_can_be_sorted_and_paginated(): void
    {
        foreach (range(1, 12) as $n) {
            $this->monster(1000 + $n, ['iName' => sprintf('Mob %02d', $n), 'LV' => $n]);
        }

        $this->getJson('/api/monsters?sort=level&direction=desc&per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.level', 12)
            ->assertJsonPath('meta.total', 12);
    }

    #[Test]
    public function an_unlisted_sort_column_is_refused(): void
    {
        $this->monster(1002);

        $this->getJson('/api/monsters?sort=Sprite')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    #[Test]
    public function a_monster_can_be_viewed_with_its_modes_decoded(): void
    {
        $this->monster(1086, [
            'iName' => 'Golden Thief Bug',
            'LV' => 65,
            'HP' => 102000,
            'ATK1' => 1500,
            'ATK2' => 2000,
            'DEF' => 80,
            'MEXP' => 200,
            'mode_aggressive' => 1,
            'mode_looter' => 1,
            'mode_mvp' => 1,
        ]);

        $response = $this->getJson('/api/monsters/1086')->assertOk();

        $response->assertJsonPath('data.name', 'Golden Thief Bug')
            ->assertJsonPath('data.attack.min', 1500)
            ->assertJsonPath('data.attack.max', 2000)
            ->assertJsonPath('data.experience.mvp', 200)
            ->assertJsonPath('data.is_mvp', true);

        $this->assertSame(['Aggressive', 'Looter', 'MVP'], $response->json('data.modes'));
    }

    #[Test]
    public function an_unknown_monster_is_a_404(): void
    {
        $this->getJson('/api/monsters/999999')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Race, size and element
    |--------------------------------------------------------------------------
    |
    | rAthena stores these two ways: the pre-renewal mob_db keeps a number and
    | mob_db_re, generated from the YAML, keeps a word. FluxCP read both.
    |
    */

    #[Test]
    public function a_renewal_monster_reports_its_race_and_size_by_name(): void
    {
        $this->monster(1002, ['Race' => 'Formless', 'Size' => 'Medium']);

        $this->getJson('/api/monsters/1002')
            ->assertOk()
            ->assertJsonPath('data.race', 'Formless')
            ->assertJsonPath('data.race_name', 'Formless')
            ->assertJsonPath('data.size', 'Medium')
            ->assertJsonPath('data.size_name', 'Medium');
    }

    /**
     * `(int) 'Formless'` is 0, so casting these to int reported race 0 and
     * size 0 for every monster on a renewal server.
     */
    #[Test]
    public function a_word_valued_race_is_not_reported_as_zero(): void
    {
        $this->monster(1002, ['Race' => 'Demihuman']);

        $response = $this->getJson('/api/monsters/1002')->assertOk();

        $this->assertNotSame(0, $response->json('data.race'));
        $this->assertSame('Demi-Human', $response->json('data.race_name'));
    }

    #[Test]
    public function a_pre_renewal_monster_reports_its_numeric_race_by_name(): void
    {
        /*
         * The registry caches its groups on first use, so flipping the renewal
         * flag here would not reach it. Pointing the reference tables at the
         * numeric mob_db exercises the same reading path.
         */
        config(['rathena.reference_tables.monsters.renewal.base' => 'mob_db']);
        config(['rathena.reference_tables.monsters.renewal.override' => 'mob_db2']);

        $this->monster(1002, ['Race' => 7, 'Size' => 2], table: 'mob_db');

        $this->getJson('/api/monsters/1002')
            ->assertOk()
            ->assertJsonPath('data.race', 7)
            ->assertJsonPath('data.race_name', 'Demi-Human')
            ->assertJsonPath('data.size', 2)
            ->assertJsonPath('data.size_name', 'Large');
    }

    /**
     * A pre-renewal server packs the element level into the element column as
     * `element + level * 20`, so 23 is a level 1 Fire monster.
     */
    #[Test]
    public function a_packed_element_is_split_into_element_and_level(): void
    {
        /*
         * The registry caches its groups on first use, so flipping the renewal
         * flag here would not reach it. Pointing the reference tables at the
         * numeric mob_db exercises the same reading path.
         */
        config(['rathena.reference_tables.monsters.renewal.base' => 'mob_db']);
        config(['rathena.reference_tables.monsters.renewal.override' => 'mob_db2']);

        $this->monster(1002, ['Element' => 23], table: 'mob_db');

        $this->getJson('/api/monsters/1002')
            ->assertOk()
            ->assertJsonPath('data.element_name', 'Fire')
            ->assertJsonPath('data.element_level', 1);
    }

    #[Test]
    public function a_renewal_element_uses_its_own_level_column(): void
    {
        $this->monster(1002, ['Element' => 'Water', 'ElementLevel' => 2]);

        $this->getJson('/api/monsters/1002')
            ->assertOk()
            ->assertJsonPath('data.element_name', 'Water')
            ->assertJsonPath('data.element_level', 2);
    }

    #[Test]
    public function an_unknown_race_value_is_reported_without_a_name(): void
    {
        // A server on a newer rAthena than this table knows about should show
        // the stored value rather than a wrong name.
        $this->monster(1002, ['Race' => 'Doppelganger']);

        $this->getJson('/api/monsters/1002')
            ->assertOk()
            ->assertJsonPath('data.race', 'Doppelganger')
            ->assertJsonPath('data.race_name', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering by them
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function monsters_can_be_filtered_by_race(): void
    {
        $this->monster(1002, ['Race' => 'Formless']);
        $this->monster(1003, ['Race' => 'Demihuman']);

        $this->getJson('/api/monsters?race=Formless')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 1002);
    }

    /**
     * One filter has to work whichever table the server has, so the term is
     * resolved to a label and the label back to every stored form of it.
     */
    #[Test]
    public function a_race_filter_accepts_the_label_the_word_or_the_number(): void
    {
        $this->monster(1002, ['Race' => 'Demihuman']);
        $this->monster(1003, ['Race' => 'Formless']);

        foreach (['Demi-Human', 'Demihuman', 'demihuman', '7'] as $term) {
            $this->getJson('/api/monsters?race='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data', "The term \"{$term}\" should find the Demi-Human.")
                ->assertJsonPath('data.0.id', 1002);
        }
    }

    #[Test]
    public function monsters_can_be_filtered_by_size(): void
    {
        $this->monster(1002, ['Size' => 'Small']);
        $this->monster(1003, ['Size' => 'Large']);

        $this->getJson('/api/monsters?size=Large')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 1003);
    }

    /**
     * Every level of an element has a different stored number on a
     * pre-renewal server, so matching one value would find only one level.
     */
    #[Test]
    public function an_element_filter_finds_every_level_of_that_element(): void
    {
        /*
         * The registry caches its groups on first use, so flipping the renewal
         * flag here would not reach it. Pointing the reference tables at the
         * numeric mob_db exercises the same reading path.
         */
        config(['rathena.reference_tables.monsters.renewal.base' => 'mob_db']);
        config(['rathena.reference_tables.monsters.renewal.override' => 'mob_db2']);

        $this->monster(1002, ['Element' => 23], table: 'mob_db');  // Fire, level 1
        $this->monster(1003, ['Element' => 43], table: 'mob_db');  // Fire, level 2
        $this->monster(1004, ['Element' => 21], table: 'mob_db');  // Water, level 1

        $response = $this->getJson('/api/monsters?element=Fire')->assertOk();

        $this->assertSame([1002, 1003], array_column($response->json('data'), 'id'));
    }

    #[Test]
    public function an_unknown_race_filter_is_a_validation_error_not_a_query(): void
    {
        $this->getJson('/api/monsters?race=NotARace')
            ->assertStatus(422)
            ->assertJsonValidationErrors('race');
    }

    #[Test]
    public function the_monster_vocabulary_lists_the_labels_once_each(): void
    {
        $response = $this->getJson('/api/monsters/vocabulary')->assertOk();

        $races = $response->json('data.races');

        $this->assertContains('Demi-Human', $races);
        // Keyed by both the number and the word, so a naive listing would
        // offer every label twice.
        $this->assertSame(array_values(array_unique($races)), $races);
        $this->assertContains('Medium', $response->json('data.sizes'));
        $this->assertContains('Fire', $response->json('data.elements'));
    }
}
