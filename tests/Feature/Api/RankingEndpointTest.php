<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The public ladders.
 *
 * Every exclusion here is one the legacy panel applied, and each exists for a
 * reason: a ladder headed by a game master with developer-granted levels, or
 * populated by banned accounts, is not a ladder players trust.
 */
final class RankingEndpointTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function character(string $name, int $level, Account $account, int $zeny = 0): Character
    {
        return Character::factory()
            ->forAccount($account)
            ->named($name)
            ->atLevel($level)
            ->withZeny($zeny)
            ->create();
    }

    #[Test]
    public function it_ranks_characters_by_level_highest_first(): void
    {
        $account = Account::factory()->create();

        $this->character('Middle', 50, $account);
        $this->character('Highest', 99, $account);
        $this->character('Lowest', 10, $account);

        $response = $this->getJson('/api/rankings/level')->assertOk();

        $this->assertSame(['Highest', 'Middle', 'Lowest'], array_column(
            array_column($response->json('data'), 'character'), 'name',
        ));
        $this->assertSame(1, $response->json('data.0.rank'));
    }

    #[Test]
    public function it_excludes_characters_of_a_permanently_banned_account(): void
    {
        $this->character('Honest', 50, Account::factory()->create());
        $this->character('Cheater', 99, Account::factory()->permanentlyBanned()->create());

        $names = $this->rankedNames('/api/rankings/level');

        $this->assertContains('Honest', $names);
        $this->assertNotContains('Cheater', $names);
    }

    #[Test]
    public function it_excludes_staff_characters(): void
    {
        // RankingHideGroupLevel in the legacy config. A game master can grant
        // themselves anything, so their characters are not comparable.
        $this->character('Player', 50, Account::factory()->create());
        $this->character('GameMaster', 99, Account::factory()->administrator()->create());
        $this->character('Support', 98, Account::factory()->juniorGameMaster()->create());

        $names = $this->rankedNames('/api/rankings/level');

        $this->assertContains('Player', $names);
        $this->assertNotContains('GameMaster', $names);
        $this->assertNotContains('Support', $names);
    }

    #[Test]
    public function it_excludes_characters_queued_for_deletion(): void
    {
        $account = Account::factory()->create();

        $this->character('Alive', 50, $account);
        Character::factory()->forAccount($account)->named('Deleted')->atLevel(99)
            ->pendingDeletion()->create();

        $names = $this->rankedNames('/api/rankings/level');

        $this->assertContains('Alive', $names);
        $this->assertNotContains('Deleted', $names);
    }

    #[Test]
    public function it_includes_temporarily_banned_accounts_by_default(): void
    {
        // The legacy default for HideTempBannedCharRank was off: a temporary
        // ban is a timeout, not a reason to erase someone's progress.
        $this->character('ServingTime', 99, Account::factory()->temporarilyBanned()->create());

        $this->assertContains('ServingTime', $this->rankedNames('/api/rankings/level'));
    }

    #[Test]
    public function it_can_be_configured_to_exclude_temporarily_banned_accounts(): void
    {
        config(['panel.rankings.hide_temporarily_banned' => true]);

        $this->character('ServingTime', 99, Account::factory()->temporarilyBanned()->create());

        $this->assertNotContains('ServingTime', $this->rankedNames('/api/rankings/level'));
    }

    #[Test]
    public function it_filters_by_job_class(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->named('Novice')->atLevel(10)->create();
        Character::factory()->forAccount($account)->named('Swordsman')->atLevel(20)
            ->state(['class' => 1])->create();

        $names = $this->rankedNames('/api/rankings/level?job_class=1');

        $this->assertSame(['Swordsman'], $names);
    }

    #[Test]
    public function it_ranks_by_zeny(): void
    {
        $account = Account::factory()->create();

        $this->character('Poor', 50, $account, zeny: 100);
        $this->character('Rich', 10, $account, zeny: 1_000_000);

        $this->assertSame(['Rich', 'Poor'], $this->rankedNames('/api/rankings/zeny'));
    }

    #[Test]
    public function the_zeny_ladder_honours_a_characters_opt_out(): void
    {
        // Players set this themselves so that being wealthy does not advertise
        // them as a target.
        $account = Account::factory()->create();

        $shy = $this->character('Shy', 10, $account, zeny: 1_000_000);
        $this->character('Proud', 10, $account, zeny: 500_000);

        DB::connection($this->serverGroup()->charMapConnection())
            ->table('cp_charprefs')
            ->insert([
                'account_id' => $account->account_id,
                'char_id' => $shy->char_id,
                'name' => 'HideFromZenyRanking',
                'value' => '1',
                'create_date' => now(),
            ]);

        $names = $this->rankedNames('/api/rankings/zeny');

        $this->assertSame(['Proud'], $names);
    }

    #[Test]
    public function the_opt_out_does_not_affect_the_level_ladder(): void
    {
        $account = Account::factory()->create();
        $character = $this->character('Shy', 99, $account, zeny: 1_000_000);

        DB::connection($this->serverGroup()->charMapConnection())
            ->table('cp_charprefs')
            ->insert([
                'account_id' => $account->account_id,
                'char_id' => $character->char_id,
                'name' => 'HideFromZenyRanking',
                'value' => '1',
                'create_date' => now(),
            ]);

        $this->assertContains('Shy', $this->rankedNames('/api/rankings/level'));
    }

    #[Test]
    public function it_clamps_an_oversized_limit(): void
    {
        // Otherwise a visitor can ask for the whole character table.
        $account = Account::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->character("Char{$i}", 10 + $i, $account);
        }

        config(['panel.pagination.max_per_page' => 3]);

        $this->getJson('/api/rankings/level?limit=10000')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_is_reachable_without_signing_in(): void
    {
        $this->getJson('/api/rankings/level')->assertOk();
        $this->getJson('/api/rankings/zeny')->assertOk();
    }

    /**
     * @return list<string>
     */
    private function rankedNames(string $url): array
    {
        $response = $this->getJson($url)->assertOk();

        return array_column(array_column($response->json('data'), 'character'), 'name');
    }
}
