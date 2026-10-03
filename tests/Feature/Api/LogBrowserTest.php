<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountLevel;
use App\Models\Account;
use App\Services\Logs\LogBrowser;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The log browsers.
 *
 * Ports the coverage for the 22 cplog/* and logdata/* modules, which are 20
 * declared views behind one endpoint here.
 *
 * Two themes run through these tests. First, that a view is readable only by
 * the level it declares — they are not equivalent, and the chat log is every
 * private message players have sent each other. Second, that a server
 * configured differently gets an empty or narrower table rather than a 500:
 * rAthena's log schema varies by version, and which tables exist at all
 * depends on `log_athena.conf`.
 */
final class LogBrowserTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function logs(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->logsConnection();
    }

    private function login(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    /*
    |--------------------------------------------------------------------------
    | The menu
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_menu_lists_every_view_an_administrator_may_open(): void
    {
        /*
         * Rendered from this rather than from a client-side list, so a menu
         * never offers something that 403s on click.
         */
        $views = $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs')->assertOk()->json('data');

        $keys = array_column($views, 'key');

        $this->assertContains('items', $keys);
        $this->assertContains('chat', $keys);
        $this->assertSame(
            count($this->app->make(LogBrowser::class)->views()),
            count($keys),
        );
    }

    #[Test]
    public function the_menu_is_filtered_by_what_each_view_requires(): void
    {
        /*
         * Every view is Administrator by default, matching the legacy access
         * file — the levels are not loosened by this port. The per-view level
         * exists so an operator *can* lower the everyday ones without also
         * handing over the chat log, and this checks that lever works.
         */
        config(['log_browsers.items.level' => AccountLevel::JuniorGameMaster]);

        $views = $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/logs')->assertOk()->json('data');

        $keys = array_column($views, 'key');

        $this->assertSame(['items'], $keys);
        $this->assertNotContains('chat', $keys);
    }

    #[Test]
    public function a_guest_cannot_list_the_logs(): void
    {
        $this->getJson('/api/logs')->assertStatus(401);
    }

    #[Test]
    public function a_player_cannot_list_the_logs(): void
    {
        $this->actingAs(Account::factory()->create())
            ->getJson('/api/logs')->assertStatus(403);
    }

    #[Test]
    public function the_logs_are_administrator_only_as_in_the_legacy(): void
    {
        foreach ([
            Account::factory()->juniorGameMaster()->create(),
            Account::factory()->seniorGameMaster()->create(),
        ] as $account) {
            $this->actingAs($account)->getJson('/api/logs/zeny')->assertStatus(403);
        }

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a view
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_view_returns_rows_with_its_column_definitions(): void
    {
        DB::connection($this->logs())->table('zenylog')->insert([
            'time' => now(), 'char_id' => 150001, 'src_id' => 150002,
            'type' => 'T', 'amount' => 5000, 'map' => 'prontera',
        ]);

        $response = $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny')
            ->assertOk();

        $response->assertJsonPath('data.0.amount', 5000)
            ->assertJsonPath('data.0.map', 'prontera')
            ->assertJsonPath('meta.label', 'Zeny movements')
            ->assertJsonPath('meta.available', true);

        // The client builds its table from this rather than carrying its own
        // copy of twenty column lists.
        $this->assertContains(
            'amount',
            array_column($response->json('meta.columns'), 'key'),
        );
    }

    #[Test]
    public function a_view_the_viewer_may_not_read_is_refused(): void
    {
        config(['log_browsers.items.level' => AccountLevel::JuniorGameMaster]);

        // Lowered for the item log only; the chat log stays where it was.
        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/logs/chat')
            ->assertStatus(403);
    }

    #[Test]
    public function an_unknown_view_is_a_404(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/nonsense')
            ->assertNotFound();
    }

    #[Test]
    public function a_log_table_this_server_does_not_keep_reports_as_unavailable(): void
    {
        /*
         * An operator who never enabled a log type has no such table. That is
         * a normal configuration, so it must be an explicit "turned off"
         * rather than a 500 on an admin page.
         */
        Schema::connection($this->logs())->drop('branchlog');
        Cache::flush();

        try {
            $this->actingAs(Account::factory()->administrator()->create())
                ->getJson('/api/logs/branches')
                ->assertOk()
                ->assertJsonPath('meta.available', false)
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        } finally {
            Schema::connection($this->logs())->create('branchlog', function ($table): void {
                $table->increments('branch_id');
                $table->dateTime('branch_date')->useCurrent();
                $table->unsignedInteger('account_id')->default(0);
                $table->unsignedInteger('char_id')->default(0);
                $table->string('char_name', 25)->default('');
                $table->string('map', 11)->default('');
            });
            Cache::flush();
        }
    }

    #[Test]
    public function a_column_this_schema_lacks_is_dropped_rather_than_selected(): void
    {
        /*
         * rAthena's log schema varies by version. A hardcoded SELECT would
         * turn "this server logs slightly differently" into a 500.
         */
        Schema::connection($this->logs())->table('zenylog', fn ($table) => $table->dropColumn('map'));
        Cache::flush();

        try {
            $response = $this->actingAs(Account::factory()->administrator()->create())
                ->getJson('/api/logs/zeny')
                ->assertOk();

            $this->assertNotContains('map', array_column($response->json('meta.columns'), 'key'));
            $this->assertNotContains('map', $response->json('meta.filters'));
        } finally {
            Schema::connection($this->logs())->table('zenylog', fn ($table) => $table->string('map', 11)->default(''));
            Cache::flush();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering, sorting and paging
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function rows_can_be_filtered(): void
    {
        $rows = [];

        foreach ([150001, 150002] as $charId) {
            $rows[] = [
                'time' => now(), 'char_id' => $charId, 'src_id' => 0,
                'type' => 'T', 'amount' => 100, 'map' => 'prontera',
            ];
        }

        DB::connection($this->logs())->table('zenylog')->insert($rows);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny?char_id=150001')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function rows_can_be_narrowed_to_a_date_range(): void
    {
        DB::connection($this->logs())->table('zenylog')->insert([
            ['time' => now()->subDays(10), 'char_id' => 1, 'src_id' => 0, 'type' => 'T', 'amount' => 1, 'map' => 'a'],
            ['time' => now(), 'char_id' => 2, 'src_id' => 0, 'type' => 'T', 'amount' => 2, 'map' => 'a'],
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.char_id', 2);
    }

    #[Test]
    public function the_to_date_includes_the_whole_day(): void
    {
        // Which is what somebody picking a date on a form means by "to".
        DB::connection($this->logs())->table('zenylog')->insert([
            'time' => now()->setTime(23, 30), 'char_id' => 1, 'src_id' => 0,
            'type' => 'T', 'amount' => 1, 'map' => 'a',
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny?to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_wildcard_in_a_text_filter_is_escaped(): void
    {
        DB::connection($this->logs())->table('zenylog')->insert([
            ['time' => now(), 'char_id' => 1, 'src_id' => 0, 'type' => 'T', 'amount' => 1, 'map' => 'prontera'],
            ['time' => now(), 'char_id' => 2, 'src_id' => 0, 'type' => 'T', 'amount' => 1, 'map' => '100%map'],
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny?map=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.map', '100%map');
    }

    #[Test]
    public function a_view_is_sorted_newest_first_by_default(): void
    {
        DB::connection($this->logs())->table('zenylog')->insert([
            ['time' => now()->subDay(), 'char_id' => 1, 'src_id' => 0, 'type' => 'T', 'amount' => 1, 'map' => 'a'],
            ['time' => now(), 'char_id' => 2, 'src_id' => 0, 'type' => 'T', 'amount' => 2, 'map' => 'a'],
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny')
            ->assertOk()
            ->assertJsonPath('data.0.char_id', 2);
    }

    #[Test]
    public function an_unlisted_sort_column_is_refused(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny?sort=;DROP')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    #[Test]
    public function a_view_paginates(): void
    {
        $rows = [];

        foreach (range(1, 25) as $n) {
            $rows[] = ['time' => now()->subMinutes($n), 'char_id' => $n, 'src_id' => 0, 'type' => 'T', 'amount' => $n, 'map' => 'a'];
        }

        DB::connection($this->logs())->table('zenylog')->insert($rows);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/zeny?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25);
    }

    /*
    |--------------------------------------------------------------------------
    | Per-view behaviour
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_chat_message_has_its_marker_stripped(): void
    {
        // rAthena pads messages with a `|00` marker, which the legacy stripped
        // with a REPLACE in SQL.
        DB::connection($this->logs())->table('chatlog')->insert([
            'time' => now(), 'type' => 'W', 'src_charid' => 150001,
            'src_charname' => 'Merchant', 'dst_charname' => 'Friend',
            'src_map' => 'prontera', 'message' => 'hello|00',
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/chat')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'hello');
    }

    #[Test]
    public function the_reset_log_never_shows_the_token(): void
    {
        /*
         * `cp_resetpass.code` is a digest rather than a working token, but a
         * log browser has no reason to show it and listing it would put it in
         * every administrator's browser history.
         */
        DB::connection($this->login())->table('cp_resetpass')->insert([
            'code' => str_repeat('a', 32), 'account_id' => 2000001,
            'request_date' => now(), 'request_ip' => '198.51.100.1', 'reset_done' => 0,
        ]);

        $response = $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/password-resets')
            ->assertOk();

        $this->assertStringNotContainsString(str_repeat('a', 32), $response->getContent());
        $this->assertNotContains('code', array_column($response->json('meta.columns'), 'key'));
    }

    #[Test]
    public function the_character_log_reads_the_char_map_connection(): void
    {
        // rAthena keeps charlog beside the character data rather than in the
        // logs database, which operators commonly put on another host.
        DB::connection($this->charMap())->table('charlog')->insert([
            'time' => now(), 'char_msg' => 'char create', 'account_id' => 2000001,
            'char_num' => 0, 'name' => 'Newbie',
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/logs/characters')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Newbie');
    }

    #[Test]
    public function every_declared_view_can_be_opened_by_an_administrator(): void
    {
        /*
         * A smoke test over all twenty. It catches a declaration naming a
         * column or table that does not exist, which is the failure mode of a
         * config-driven design.
         */
        $admin = Account::factory()->administrator()->create();
        $browser = $this->app->make(LogBrowser::class);

        foreach (array_keys($browser->views()) as $key) {
            $this->actingAs($admin)
                ->getJson("/api/logs/{$key}")
                ->assertOk();
        }
    }
}
