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
}
