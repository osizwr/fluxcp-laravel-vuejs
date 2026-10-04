<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Models\Guild;
use App\Models\NewsArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The endpoints that exist so the front-page blocks have real data behind them.
 *
 * The point of these tests is that the figures are genuine. A statistics block
 * is worth nothing if its numbers are decorative, and the brief for this work
 * was explicit that nothing may be simulated.
 */
final class SiteDataEndpointTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These endpoints cache, so a stale entry from a sibling test would
        // make the assertions meaningless.
        Cache::flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_counts_accounts_characters_guilds_and_players_online(): void
    {
        $account = Account::factory()->create();
        Account::factory()->count(2)->create();

        Character::factory()->forAccount($account)->count(3)->create();
        Character::factory()->forAccount($account)->online()->count(2)->create();
        Guild::factory()->count(4)->create();

        $this->getJson('/api/server/statistics')
            ->assertOk()
            ->assertJsonPath('data.accounts', 3)
            ->assertJsonPath('data.characters', 5)
            ->assertJsonPath('data.guilds', 4)
            ->assertJsonPath('data.players_online', 2);
    }

    #[Test]
    public function statistics_exclude_server_accounts_and_disabled_accounts(): void
    {
        // rAthena's own inter-server account, and a disabled one. Counting
        // either would overstate the player base.
        Account::factory()->create();
        Account::factory()->serverAccount()->create();
        Account::factory()->disabled()->create();

        $this->getJson('/api/server/statistics')
            ->assertOk()
            ->assertJsonPath('data.accounts', 1);
    }

    #[Test]
    public function statistics_exclude_characters_queued_for_deletion(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->create();
        Character::factory()->forAccount($account)->pendingDeletion()->create();

        $this->getJson('/api/server/statistics')
            ->assertOk()
            ->assertJsonPath('data.characters', 1);
    }

    #[Test]
    public function statistics_report_no_uptime(): void
    {
        /*
         * Deliberate. rAthena records no start time the panel can read, so an
         * uptime figure would have to be invented -- and a statistics endpoint
         * is the last place an invented number belongs.
         */
        $data = $this->getJson('/api/server/statistics')->assertOk()->json('data');

        $this->assertArrayNotHasKey('uptime', $data);

        // The whole key set is pinned, so a figure cannot be added here
        // without somebody deciding it is one the panel can actually source.
        $this->assertSame(
            ['accounts', 'characters', 'guilds', 'parties', 'zeny', 'players_online', 'rates'],
            array_keys($data),
        );
    }

    #[Test]
    public function statistics_include_the_figures_the_legacy_server_info_page_showed(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->state(['zeny' => 1000])->create();
        Character::factory()->forAccount($account)->state(['zeny' => 500])->create();

        $response = $this->getJson('/api/server/info')->assertOk();

        $response->assertJsonPath('data.zeny', 1500)
            ->assertJsonPath('data.rates.renewal', true);

        /*
         * Rates are the operator's declaration, not something read from the
         * emulator -- rAthena keeps them in conf files the panel cannot see.
         * The flag says which it is, so a page can avoid presenting defaults
         * as fact.
         */
        $this->assertIsBool($response->json('data.rates.declared'));
    }

    /**
     * The total-zeny figure is what an operator watches for inflation, so a
     * game master who granted themselves two billion for a test must not move
     * it. FluxCP's InfoHideZenyGroupLevel.
     */
    #[Test]
    public function staff_zeny_is_left_out_of_the_total(): void
    {
        Character::factory()->forAccount(Account::factory()->create())
            ->state(['zeny' => 1000])->create();

        Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->state(['zeny' => 2_000_000_000])->create();

        $this->getJson('/api/server/info')
            ->assertOk()
            ->assertJsonPath('data.zeny', 1000);
    }

    #[Test]
    public function staff_zeny_is_counted_when_the_operator_turns_the_filter_off(): void
    {
        config(['panel.statistics.hide_zeny_at_or_above_level' => null]);

        Character::factory()->forAccount(Account::factory()->create())
            ->state(['zeny' => 1000])->create();

        Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->state(['zeny' => 500])->create();

        $this->getJson('/api/server/info')
            ->assertOk()
            ->assertJsonPath('data.zeny', 1500);
    }

    #[Test]
    public function parties_are_counted_when_the_table_exists(): void
    {
        Cache::flush();

        DB::connection($this->serverGroup()->charMapConnection())->table('party')->insert([
            ['name' => 'One', 'leader_char' => 0],
            ['name' => 'Two', 'leader_char' => 0],
        ]);

        $this->getJson('/api/server/statistics')
            ->assertOk()
            ->assertJsonPath('data.parties', 2);
    }

    /**
     * Zero would read as "nobody has a party" on a server whose schema simply
     * does not have the table.
     *
     * The table is renamed rather than dropped, and restored whatever happens,
     * because the schema is built once for the class and a test that destroys
     * part of it fails every test after it.
     */
    #[Test]
    public function a_missing_party_table_reports_null_rather_than_zero(): void
    {
        Cache::flush();

        $schema = Schema::connection($this->serverGroup()->charMapConnection());

        $schema->rename('party', 'party_hidden_for_test');

        try {
            $this->getJson('/api/server/statistics')
                ->assertOk()
                ->assertJsonPath('data.parties', null);
        } finally {
            $schema->rename('party_hidden_for_test', 'party');
        }
    }

    #[Test]
    public function the_statistics_endpoint_is_public(): void
    {
        $this->getJson('/api/server/statistics')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Class distribution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_counts_characters_per_job_class(): void
    {
        $account = Account::factory()->create();

        // Job 0 is Novice, 1 is Swordsman -- resolved server-side from the
        // ported reference data so the client carries no job table.
        Character::factory()->forAccount($account)->count(3)->state(['class' => 0])->create();
        Character::factory()->forAccount($account)->count(5)->state(['class' => 1])->create();

        $response = $this->getJson('/api/characters/classes')->assertOk();

        $response
            ->assertJsonPath('data.0.job_id', 1)
            ->assertJsonPath('data.0.job_name', 'Swordsman')
            ->assertJsonPath('data.0.characters', 5)
            ->assertJsonPath('data.1.job_id', 0)
            ->assertJsonPath('data.1.job_name', 'Novice')
            ->assertJsonPath('data.1.characters', 3);
    }

    #[Test]
    public function the_class_distribution_is_ordered_by_popularity(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->count(1)->state(['class' => 0])->create();
        Character::factory()->forAccount($account)->count(9)->state(['class' => 2])->create();
        Character::factory()->forAccount($account)->count(4)->state(['class' => 3])->create();

        $counts = array_column($this->getJson('/api/characters/classes')->json('data'), 'characters');

        $this->assertSame([9, 4, 1], $counts);
    }

    #[Test]
    public function the_class_distribution_clamps_its_limit(): void
    {
        $account = Account::factory()->create();

        for ($class = 0; $class < 6; $class++) {
            Character::factory()->forAccount($account)->state(['class' => $class])->create();
        }

        $this->getJson('/api/characters/classes?limit=2')->assertOk()->assertJsonCount(2, 'data');
        // Above the ceiling, not an error.
        $this->getJson('/api/characters/classes?limit=9999')->assertOk();
    }

    #[Test]
    public function the_class_distribution_is_empty_when_there_are_no_characters(): void
    {
        // An empty block is correct here. Inventing classes to fill it would
        // be exactly the kind of decoration this endpoint exists to avoid.
        $this->getJson('/api/characters/classes')->assertOk()->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | News
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_news_newest_first(): void
    {
        NewsArticle::factory()->titled('Older')->publishedAt('2026-01-01 10:00:00')->create();
        NewsArticle::factory()->titled('Newer')->publishedAt('2026-06-01 10:00:00')->create();

        $titles = array_column($this->getJson('/api/news')->assertOk()->json('data'), 'title');

        $this->assertSame(['Newer', 'Older'], $titles);
    }

    #[Test]
    public function a_news_listing_sends_an_excerpt_rather_than_the_whole_body(): void
    {
        /*
         * A listing of twenty articles would otherwise ship twenty rich-text
         * documents to render three lines of each.
         */
        NewsArticle::factory()
            ->titled('Season opens')
            ->withBody('<p>'.str_repeat('Adventurers assemble. ', 40).'</p>')
            ->create();

        $article = $this->getJson('/api/news')->assertOk()->json('data.0');

        $this->assertArrayHasKey('excerpt', $article);
        $this->assertArrayNotHasKey('body', $article);
        $this->assertLessThan(200, strlen((string) $article['excerpt']));
        // Tags stripped: truncating HTML at a character count breaks markup.
        $this->assertStringNotContainsString('<p>', (string) $article['excerpt']);
        $this->assertStringEndsWith('…', (string) $article['excerpt']);
    }

    #[Test]
    public function a_single_article_includes_its_body(): void
    {
        $article = NewsArticle::factory()
            ->titled('Patch notes')
            ->withBody('<p>The full <strong>body</strong>.</p>')
            ->create();

        $this->getJson("/api/news/{$article->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Patch notes')
            ->assertJsonPath('data.body', '<p>The full <strong>body</strong>.</p>');
    }

    #[Test]
    public function an_unknown_article_is_a_404(): void
    {
        $this->getJson('/api/news/999999')->assertStatus(404);
    }

    #[Test]
    public function an_article_link_is_null_when_none_was_set(): void
    {
        // So the client renders no link rather than an empty href.
        NewsArticle::factory()->titled('No link')->create();
        NewsArticle::factory()->titled('Linked')->linkingTo('https://example.test/post')->create();

        $articles = collect($this->getJson('/api/news')->json('data'))->keyBy('title');

        $this->assertNull($articles['No link']['link']);
        $this->assertSame('https://example.test/post', $articles['Linked']['link']);
    }

    #[Test]
    public function the_news_endpoints_are_public(): void
    {
        $article = NewsArticle::factory()->create();

        $this->getJson('/api/news')->assertOk();
        $this->getJson("/api/news/{$article->id}")->assertOk();
    }

    #[Test]
    public function news_is_paginated(): void
    {
        NewsArticle::factory()->count(5)->create();

        $this->getJson('/api/news?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
    }
}
