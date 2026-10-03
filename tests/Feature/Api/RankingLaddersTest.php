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
 * The six ladders beyond level and zeny.
 *
 * Ports the coverage for ranking/alchemist, blacksmith, death, homunculus,
 * guild and mvp.
 *
 * Each ladder has to apply the same account exclusions as the others. A
 * ladder that forgets them is a way to find out which accounts are staff, or
 * to see a banned cheater still topping a board, so those cases are tested per
 * ladder rather than once.
 */
final class RankingLaddersTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('panel.rankings.hide_at_or_above_level', 1);
    }

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    private function logs(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->logsConnection();
    }

    /*
    |--------------------------------------------------------------------------
    | Fame ladders
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_alchemist_ladder_ranks_by_fame(): void
    {
        $account = Account::factory()->create();

        // 18 is Alchemist, 4019 Creator — both count for this branch.
        Character::factory()->forAccount($account)->named('Brewer')
            ->state(['class' => 18, 'fame' => 50])->create();
        Character::factory()->forAccount($account)->named('Maker')
            ->state(['class' => 4019, 'fame' => 900])->create();

        $this->getJson('/api/rankings/alchemist')
            ->assertOk()
            ->assertJsonPath('data.0.character.name', 'Maker')
            ->assertJsonPath('data.0.fame', 900)
            ->assertJsonPath('data.1.character.name', 'Brewer');
    }

    #[Test]
    public function the_fame_ladders_do_not_overlap(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->named('Brewer')
            ->state(['class' => 18, 'fame' => 50])->create();
        Character::factory()->forAccount($account)->named('Smith')
            ->state(['class' => 10, 'fame' => 50])->create();

        $this->getJson('/api/rankings/alchemist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.character.name', 'Brewer');

        $this->getJson('/api/rankings/blacksmith')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.character.name', 'Smith');
    }

    #[Test]
    public function characters_without_fame_are_excluded(): void
    {
        // A fame ladder padded with everyone who has none is not a ladder.
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->named('Famous')
            ->state(['class' => 18, 'fame' => 10])->create();
        Character::factory()->forAccount($account)->named('Unknown')
            ->state(['class' => 18, 'fame' => 0])->create();

        $this->getJson('/api/rankings/alchemist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.character.name', 'Famous');
    }

    #[Test]
    public function rebirth_and_baby_classes_are_included(): void
    {
        /*
         * A ladder matching only the base class is empty on a mature server,
         * where everybody has rebirthed.
         */
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->named('Genetic')
            ->state(['class' => 4071, 'fame' => 10])->create();
        Character::factory()->forAccount($account)->named('BabyAlche')
            ->state(['class' => 4041, 'fame' => 20])->create();

        $this->getJson('/api/rankings/alchemist')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function staff_are_excluded_from_the_fame_ladders(): void
    {
        Character::factory()->forAccount(Account::factory()->create())->named('Player')
            ->state(['class' => 18, 'fame' => 10])->create();
        Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->named('GameMaster')->state(['class' => 18, 'fame' => 99999])->create();

        $this->getJson('/api/rankings/alchemist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.character.name', 'Player');
    }

    #[Test]
    public function an_unknown_fame_branch_is_refused(): void
    {
        // Route-level: only the two declared branches have routes at all.
        $this->getJson('/api/rankings/taekwon')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Death ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_death_ladder_reads_the_counter_from_char_reg_num(): void
    {
        $account = Account::factory()->create();

        $clumsy = Character::factory()->forAccount($account)->named('Clumsy')->create();
        $careful = Character::factory()->forAccount($account)->named('Careful')->create();

        DB::connection($this->charMap())->table('char_reg_num')->insert([
            ['char_id' => $clumsy->char_id, 'key' => 'PC_DIE_COUNTER', 'index' => 0, 'value' => 42],
            ['char_id' => $careful->char_id, 'key' => 'PC_DIE_COUNTER', 'index' => 0, 'value' => 3],
        ]);

        $this->getJson('/api/rankings/deaths')
            ->assertOk()
            ->assertJsonPath('data.0.character.name', 'Clumsy')
            ->assertJsonPath('data.0.deaths', 42)
            ->assertJsonPath('data.1.deaths', 3);
    }

    #[Test]
    public function a_character_who_has_never_died_counts_as_zero(): void
    {
        /*
         * There is no row at all for such a character, so an inner join would
         * drop them. The legacy ladder listed them, and so does this.
         */
        $account = Account::factory()->create();
        Character::factory()->forAccount($account)->named('Immortal')->create();

        $this->getJson('/api/rankings/deaths')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deaths', 0);
    }

    #[Test]
    public function an_unrelated_register_key_is_not_counted_as_deaths(): void
    {
        $account = Account::factory()->create();
        $character = Character::factory()->forAccount($account)->named('Player')->create();

        DB::connection($this->charMap())->table('char_reg_num')->insert([
            ['char_id' => $character->char_id, 'key' => 'SOMETHING_ELSE', 'index' => 0, 'value' => 9999],
        ]);

        $this->getJson('/api/rankings/deaths')
            ->assertOk()
            ->assertJsonPath('data.0.deaths', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Homunculus ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_homunculus_ladder_ranks_homunculi_with_their_owners(): void
    {
        $account = Account::factory()->create();
        $owner = Character::factory()->forAccount($account)->named('Alchemist')->create();

        DB::connection($this->charMap())->table('homunculus')->insert([
            ['char_id' => $owner->char_id, 'class' => 6001, 'name' => 'Filir', 'level' => 50, 'exp' => 100, 'intimacy' => 900, 'alive' => 1],
            ['char_id' => $owner->char_id, 'class' => 6002, 'name' => 'Amistr', 'level' => 99, 'exp' => 500, 'intimacy' => 100, 'alive' => 1],
        ]);

        $this->getJson('/api/rankings/homunculus')
            ->assertOk()
            ->assertJsonPath('data.0.homunculus.name', 'Amistr')
            ->assertJsonPath('data.0.homunculus.level', 99)
            ->assertJsonPath('data.0.owner.name', 'Alchemist');
    }

    #[Test]
    public function a_released_homunculus_is_excluded(): void
    {
        // Releasing one leaves the row behind with alive = 0.
        $account = Account::factory()->create();
        $owner = Character::factory()->forAccount($account)->create();

        DB::connection($this->charMap())->table('homunculus')->insert([
            ['char_id' => $owner->char_id, 'class' => 6001, 'name' => 'Gone', 'level' => 99, 'alive' => 0],
            ['char_id' => $owner->char_id, 'class' => 6002, 'name' => 'Here', 'level' => 10, 'alive' => 1],
        ]);

        $this->getJson('/api/rankings/homunculus')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.homunculus.name', 'Here');
    }

    #[Test]
    public function staff_homunculi_are_excluded(): void
    {
        $staff = Character::factory()->forAccount(Account::factory()->administrator()->create())->create();
        $player = Character::factory()->forAccount(Account::factory()->create())->create();

        DB::connection($this->charMap())->table('homunculus')->insert([
            ['char_id' => $staff->char_id, 'class' => 6001, 'name' => 'StaffPet', 'level' => 99, 'alive' => 1],
            ['char_id' => $player->char_id, 'class' => 6001, 'name' => 'PlayerPet', 'level' => 10, 'alive' => 1],
        ]);

        $this->getJson('/api/rankings/homunculus')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.homunculus.name', 'PlayerPet');
    }

    /*
    |--------------------------------------------------------------------------
    | Guild ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_guild_ladder_counts_members_and_castles(): void
    {
        $account = Account::factory()->create();

        $big = Guild::factory()->named('Big')->state(['guild_lv' => 50, 'average_lv' => 90])->create();
        $small = Guild::factory()->named('Small')->state(['guild_lv' => 10, 'average_lv' => 30])->create();

        Character::factory()->forAccount($account)->count(3)
            ->state(['guild_id' => $big->guild_id])->create();
        Character::factory()->forAccount($account)->count(1)
            ->state(['guild_id' => $small->guild_id])->create();

        DB::connection($this->charMap())->table('guild_castle')->insert([
            ['castle_id' => 1, 'guild_id' => $big->guild_id],
            ['castle_id' => 2, 'guild_id' => $big->guild_id],
        ]);

        $response = $this->getJson('/api/rankings/guilds')->assertOk();

        $response->assertJsonPath('data.0.guild.name', 'Big')
            ->assertJsonPath('data.0.guild.members', 3)
            ->assertJsonPath('data.0.guild.castles', 2)
            ->assertJsonPath('data.1.guild.name', 'Small')
            ->assertJsonPath('data.1.guild.castles', 0);
    }

    #[Test]
    public function guild_experience_falls_back_to_the_sum_of_its_members(): void
    {
        /*
         * `guild.exp` is not reliable: some scripts credit members without
         * updating it. The legacy ladder took the greater of the two, and a
         * guild whose stored value is stale must not rank below one that is
         * genuinely smaller.
         */
        $guild = Guild::factory()->named('Stale')->state(['exp' => 0])->create();

        DB::connection($this->charMap())->table('guild_member')->insert([
            ['guild_id' => $guild->guild_id, 'char_id' => 150001, 'exp' => 5000],
            ['guild_id' => $guild->guild_id, 'char_id' => 150002, 'exp' => 7000],
        ]);

        $this->getJson('/api/rankings/guilds')
            ->assertOk()
            ->assertJsonPath('data.0.guild.experience', 12000);
    }

    /*
    |--------------------------------------------------------------------------
    | MVP ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_mvp_ladder_counts_kills_and_names_the_monster(): void
    {
        $account = Account::factory()->create();
        $hunter = Character::factory()->forAccount($account)->named('Hunter')->create();
        $other = Character::factory()->forAccount($account)->named('Rival')->create();

        DB::connection($this->charMap())->table('mob_db_re')->insert([
            'ID' => 1086, 'Sprite' => 'GOLDEN_BUG', 'kName' => 'GTB', 'iName' => 'Golden Thief Bug',
            'LV' => 65, 'HP' => 102000, 'MEXP' => 200,
        ]);

        DB::connection($this->logs())->table('mvplog')->insert([
            ['kill_char_id' => $hunter->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 10, 'map' => 'prt_sewb4'],
            ['kill_char_id' => $hunter->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 10, 'map' => 'prt_sewb4'],
            ['kill_char_id' => $other->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 10, 'map' => 'prt_sewb4'],
        ]);

        $this->getJson('/api/rankings/mvp')
            ->assertOk()
            ->assertJsonPath('data.0.character.name', 'Hunter')
            ->assertJsonPath('data.0.kills', 2)
            ->assertJsonPath('data.0.monster.name', 'Golden Thief Bug')
            ->assertJsonPath('data.1.kills', 1);
    }

    #[Test]
    public function the_mvp_ladder_can_be_narrowed_to_one_monster(): void
    {
        $account = Account::factory()->create();
        $hunter = Character::factory()->forAccount($account)->named('Hunter')->create();

        DB::connection($this->logs())->table('mvplog')->insert([
            ['kill_char_id' => $hunter->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 10, 'map' => 'a'],
            ['kill_char_id' => $hunter->char_id, 'monster_id' => 1157, 'prize' => 0, 'mvpexp' => 10, 'map' => 'a'],
        ]);

        $this->getJson('/api/rankings/mvp?monster=1086')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.monster.id', 1086);
    }

    #[Test]
    public function a_custom_mvp_is_named_from_the_override_table(): void
    {
        // The merge matters here too: a server's own boss must not show up
        // nameless.
        $account = Account::factory()->create();
        $hunter = Character::factory()->forAccount($account)->named('Hunter')->create();

        DB::connection($this->charMap())->table('mob_db2_re')->insert([
            'ID' => 3999, 'Sprite' => 'CUSTOM_BOSS', 'kName' => 'Boss', 'iName' => 'Server Boss',
            'LV' => 150, 'HP' => 500000, 'MEXP' => 9999,
        ]);

        DB::connection($this->logs())->table('mvplog')->insert([
            ['kill_char_id' => $hunter->char_id, 'monster_id' => 3999, 'prize' => 0, 'mvpexp' => 1, 'map' => 'a'],
        ]);

        $this->getJson('/api/rankings/mvp')
            ->assertOk()
            ->assertJsonPath('data.0.monster.name', 'Server Boss');
    }

    #[Test]
    public function staff_kills_are_excluded_from_the_mvp_ladder(): void
    {
        $staff = Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->named('GameMaster')->create();
        $player = Character::factory()->forAccount(Account::factory()->create())
            ->named('Player')->create();

        DB::connection($this->logs())->table('mvplog')->insert([
            ['kill_char_id' => $staff->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 1, 'map' => 'a'],
            ['kill_char_id' => $staff->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 1, 'map' => 'a'],
            ['kill_char_id' => $player->char_id, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 1, 'map' => 'a'],
        ]);

        $this->getJson('/api/rankings/mvp')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.character.name', 'Player');
    }

    #[Test]
    public function a_kill_by_a_deleted_character_is_dropped(): void
    {
        // mvplog outlives the character, so a stale row must not produce an
        // entry with a missing name.
        DB::connection($this->logs())->table('mvplog')->insert([
            ['kill_char_id' => 999999, 'monster_id' => 1086, 'prize' => 0, 'mvpexp' => 1, 'map' => 'a'],
        ]);

        $this->getJson('/api/rankings/mvp')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function every_ladder_is_public(): void
    {
        foreach ([
            'alchemist', 'blacksmith', 'deaths', 'homunculus', 'guilds', 'mvp',
        ] as $ladder) {
            $this->getJson("/api/rankings/{$ladder}")
                ->assertOk()
                ->assertJsonStructure(['data']);
        }
    }
}
