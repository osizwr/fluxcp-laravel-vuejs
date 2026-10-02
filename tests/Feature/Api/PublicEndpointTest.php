<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Contracts\ProbesServerReachability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\Support\FakeServerProbe;
use Tests\TestCase;

/**
 * Server status, the who-is-online listing, and the deny-by-default guarantee.
 */
final class PublicEndpointTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    /**
     * Replace the status probe so no test opens a real socket.
     */
    private function fakeProbe(bool $reachable): FakeServerProbe
    {
        $probe = new FakeServerProbe($reachable);

        $this->app->instance(ProbesServerReachability::class, $probe);

        return $probe;
    }

    /*
    |--------------------------------------------------------------------------
    | Server status
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_reports_server_status(): void
    {
        $this->fakeProbe(true);

        Character::factory()->online()->count(3)->create();
        Character::factory()->count(2)->create();

        $response = $this->getJson('/api/server/status')->assertOk();

        $response->assertJsonPath('data.0.login_server_up', true)
            ->assertJsonPath('data.0.servers.0.char_server_up', true)
            ->assertJsonPath('data.0.servers.0.map_server_up', true)
            ->assertJsonPath('data.0.servers.0.playable', true)
            // Counted from rAthena's own char.online column, not estimated.
            ->assertJsonPath('data.0.servers.0.players_online', 3)
            ->assertJsonPath('meta.players_online', 3);
    }

    #[Test]
    public function it_reports_a_server_as_unplayable_when_a_process_is_down(): void
    {
        $this->fakeProbe(false);

        $this->getJson('/api/server/status')
            ->assertOk()
            ->assertJsonPath('data.0.login_server_up', false)
            ->assertJsonPath('data.0.servers.0.playable', false);
    }

    #[Test]
    public function it_withholds_the_peak_count_unless_it_is_enabled(): void
    {
        $this->fakeProbe(true);

        $this->getJson('/api/server/status')
            ->assertOk()
            ->assertJsonPath('data.0.servers.0.players_peak', null);
    }

    #[Test]
    public function it_reports_the_peak_count_from_the_right_database_when_enabled(): void
    {
        // The legacy implementation read the peak through the session's
        // preferred server while iterating over every other one, so with more
        // than one char/map pair it reported the wrong figure.
        config(['panel.server_status.show_peak' => true]);
        $this->fakeProbe(true);

        DB::connection($this->serverGroup()->charMapConnection())
            ->table('cp_onlinepeak')
            ->insert(['users' => 1247, 'date' => now()->toDateString()]);

        $this->getJson('/api/server/status')
            ->assertOk()
            ->assertJsonPath('data.0.servers.0.players_peak', 1247);
    }

    #[Test]
    public function the_status_endpoint_is_public(): void
    {
        $this->fakeProbe(true);

        $this->getJson('/api/server/status')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Who is online
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_online_characters(): void
    {
        Character::factory()->online()->named('Awake')->create();
        Character::factory()->named('Asleep')->create();

        $response = $this->getJson('/api/characters/online')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertSame(['Awake'], $names);
    }

    #[Test]
    public function it_withholds_a_characters_location_from_an_ordinary_viewer(): void
    {
        // A public page showing locations lets players track each other, and
        // during a siege lets guilds scout castle defences.
        Character::factory()->online()->named('Awake')->onMap('prontera')->create();

        $response = $this->getJson('/api/characters/online')->assertOk();

        $this->assertArrayNotHasKey('map', $response->json('data.0'));
    }

    #[Test]
    public function it_shows_a_characters_location_to_staff_who_may_see_it(): void
    {
        Character::factory()->online()->named('Awake')->onMap('prontera')->create();

        // ViewOnlinePosition sits at the junior game master tier.
        $staff = Account::factory()->juniorGameMaster()->create();

        $this->actingAs($staff)
            ->getJson('/api/characters/online')
            ->assertOk()
            ->assertJsonPath('data.0.map', 'prontera');
    }

    #[Test]
    public function it_hides_characters_whose_owner_asked_to_be_hidden(): void
    {
        $account = Account::factory()->create();
        $hidden = Character::factory()->forAccount($account)->online()->named('Ghost')->create();
        Character::factory()->forAccount($account)->online()->named('Visible')->create();

        DB::connection($this->serverGroup()->charMapConnection())
            ->table('cp_charprefs')
            ->insert([
                'account_id' => $account->account_id,
                'char_id' => $hidden->char_id,
                'name' => 'Hidden',
                'value' => '1',
                'create_date' => now(),
            ]);

        $names = array_column($this->getJson('/api/characters/online')->json('data'), 'name');

        $this->assertSame(['Visible'], $names);
    }

    #[Test]
    public function staff_who_may_ignore_the_hidden_preference_still_see_them(): void
    {
        $account = Account::factory()->create();
        $hidden = Character::factory()->forAccount($account)->online()->named('Ghost')->create();

        DB::connection($this->serverGroup()->charMapConnection())
            ->table('cp_charprefs')
            ->insert([
                'account_id' => $account->account_id,
                'char_id' => $hidden->char_id,
                'name' => 'Hidden',
                'value' => '1',
                'create_date' => now(),
            ]);

        $staff = Account::factory()->juniorGameMaster()->create();

        $names = array_column(
            $this->actingAs($staff)->getJson('/api/characters/online')->json('data'),
            'name',
        );

        $this->assertSame(['Ghost'], $names);
    }

    #[Test]
    public function it_refuses_the_online_listing_during_war_of_emperium(): void
    {
        // Scheduled so that it is in progress right now, whenever "now" is.
        $this->configureWoeInProgress();

        Character::factory()->online()->create();

        $this->getJson('/api/characters/online')->assertStatus(503);
    }

    #[Test]
    public function staff_may_view_the_online_listing_during_war_of_emperium(): void
    {
        $this->configureWoeInProgress();

        Character::factory()->online()->named('Awake')->create();

        $staff = Account::factory()->juniorGameMaster()->create();

        $this->actingAs($staff)->getJson('/api/characters/online')->assertOk();
    }

    private function configureWoeInProgress(): void
    {
        $today = (int) now()->dayOfWeek;

        config([
            'rathena.groups.main.char_map_servers.main.woe_schedule' => [
                ['day' => $today, 'start' => '00:00', 'end_day' => $today, 'end' => '23:59'],
            ],
        ]);

        $this->app->forgetInstance(\App\Support\Rathena\ServerRegistry::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Own characters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_only_the_signed_in_accounts_characters(): void
    {
        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();

        Character::factory()->forAccount($mine)->named('Mine')->create();
        Character::factory()->forAccount($theirs)->named('Theirs')->create();

        $names = array_column(
            $this->actingAs($mine)->getJson('/api/characters/mine')->json('data'),
            'name',
        );

        $this->assertSame(['Mine'], $names);
    }

    #[Test]
    public function the_own_character_listing_requires_a_session(): void
    {
        $this->getJson('/api/characters/mine')->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Deny by default (D3)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_route_with_no_permission_entry_is_refused(): void
    {
        // This is the legacy fail-open bug, pinned shut. FluxCP's check
        // returned -1 for an unknown action and its dispatcher only blocked on
        // a strict false, so ten shipped actions were served to anyone.
        Route::middleware(['web', 'permission'])
            ->get('api/test/unmapped', fn () => response()->json(['reached' => true]))
            ->name('nothing.declared-this');

        $this->getJson('/api/test/unmapped')->assertStatus(403);
    }
}
