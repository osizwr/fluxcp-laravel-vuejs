<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Models\Guild;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Staff search, account editing, and the legacy status feed.
 *
 * Ports the coverage for account/index, account/edit, character/index and
 * server/status-xml.
 */
final class AdminScreensTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    /*
    |--------------------------------------------------------------------------
    | Account search
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_account_search_is_staff_only(): void
    {
        $this->getJson('/api/admin/accounts')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/admin/accounts')->assertStatus(403);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/admin/accounts')->assertOk();
    }

    #[Test]
    public function accounts_can_be_searched_by_name_and_email(): void
    {
        Account::factory()->named('merchant')->state(['email' => 'a@example.test'])->create();
        Account::factory()->named('knight')->state(['email' => 'b@example.test'])->create();

        $staff = Account::factory()->juniorGameMaster()->create();

        $this->actingAs($staff)->getJson('/api/admin/accounts?username=merch')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'merchant');

        $this->actingAs($staff)->getJson('/api/admin/accounts?email=b@example.test')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'knight');
    }

    #[Test]
    public function the_search_never_returns_a_password(): void
    {
        /*
         * The legacy search also accepted a `password` parameter and matched
         * it against login.user_pass. On a server storing cleartext that finds
         * every account sharing a password, and confirms a guess against the
         * whole player base at once. It is not ported, and the column is not
         * selected either.
         */
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $response = $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/admin/accounts?password=Zeny4Days!')
            ->assertOk();

        $this->assertStringNotContainsString('Zeny4Days!', $response->getContent());
        $this->assertStringNotContainsString('user_pass', $response->getContent());

        // The parameter is simply not a filter, so it narrows nothing.
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function numeric_filters_use_named_operators(): void
    {
        Account::factory()->named('new')->state(['logincount' => 1])->create();
        Account::factory()->named('veteran')->state(['logincount' => 500])->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/admin/accounts?login_count=100&login_count_op=gte')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'veteran');
    }

    #[Test]
    public function accounts_can_be_filtered_by_last_login_range(): void
    {
        Account::factory()->named('recent')->state(['lastlogin' => now()->subDay()])->create();
        Account::factory()->named('stale')->state(['lastlogin' => now()->subYear()])->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/admin/accounts?last_login_after='.now()->subWeek()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'recent');
    }

    /*
    |--------------------------------------------------------------------------
    | Character search
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function characters_can_be_searched_across_accounts(): void
    {
        $guild = Guild::factory()->named('Valkyrie')->create();

        Character::factory()->forAccount(Account::factory()->create())->named('Hero')
            ->state(['base_level' => 99, 'guild_id' => $guild->guild_id])->create();
        Character::factory()->forAccount(Account::factory()->create())->named('Novice')
            ->state(['base_level' => 5])->create();

        $staff = Account::factory()->juniorGameMaster()->create();

        $this->actingAs($staff)->getJson('/api/admin/characters?base_level=50&base_level_op=gte')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Hero')
            ->assertJsonPath('data.0.guild.name', 'Valkyrie');

        $this->actingAs($staff)->getJson('/api/admin/characters?name=novi')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_character_search_is_staff_only(): void
    {
        $this->actingAs(Account::factory()->create())
            ->getJson('/api/admin/characters')->assertStatus(403);
    }

    #[Test]
    public function a_wildcard_in_a_search_term_is_escaped(): void
    {
        Character::factory()->forAccount(Account::factory()->create())->named('Hero')->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/admin/characters?name=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Account detail
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function staff_can_view_one_account_in_full(): void
    {
        $player = Account::factory()->named('player')->state(['logincount' => 12])->create();

        Character::factory()->forAccount($player)->named('Hero')->state(['char_num' => 0])->create();

        DB::connection($this->loginConnection())->table('cp_credits')->insert([
            'account_id' => $player->account_id, 'balance' => 250,
        ]);

        DB::connection($this->loginConnection())->table('cp_banlog')->insert([
            'account_id' => $player->account_id, 'banned_by' => null, 'ban_type' => 2,
            'ban_until' => '9999-12-31 23:59:59', 'ban_date' => now(), 'ban_reason' => 'Botting',
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson("/api/admin/accounts/{$player->account_id}")
            ->assertOk()
            ->assertJsonPath('data.username', 'player')
            ->assertJsonPath('data.credits', 250)
            ->assertJsonPath('data.activity.login_count', 12)
            ->assertJsonPath('data.characters.0.name', 'Hero')
            ->assertJsonPath('data.recent_bans.0.reason', 'Botting');
    }

    #[Test]
    public function the_detail_view_never_carries_a_credential(): void
    {
        $player = Account::factory()->named('player')->withPassword('Zeny4Days!')->create();

        $response = $this->actingAs(Account::factory()->administrator()->create())
            ->getJson("/api/admin/accounts/{$player->account_id}")
            ->assertOk();

        $this->assertStringNotContainsString('Zeny4Days!', $response->getContent());
        $this->assertStringNotContainsString('user_pass', $response->getContent());
    }

    #[Test]
    public function staff_cannot_view_an_account_above_their_own_rank(): void
    {
        /*
         * Reading an account above your own is how a junior game master finds
         * out which address an administrator signs in from.
         */
        $target = Account::factory()->administrator()->create();

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson("/api/admin/accounts/{$target->account_id}")
            ->assertStatus(403);
    }

    #[Test]
    public function a_player_cannot_view_another_account(): void
    {
        $target = Account::factory()->create();

        $this->actingAs(Account::factory()->create())
            ->getJson("/api/admin/accounts/{$target->account_id}")
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Account editing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_administrator_can_edit_a_players_account(): void
    {
        $player = Account::factory()->named('player')->state(['email' => 'old@example.test'])->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson("/api/admin/accounts/{$player->account_id}", [
                'email' => 'new@example.test',
            ])->assertOk();

        $this->assertSame('new@example.test', $player->refresh()->email);
    }

    #[Test]
    public function the_password_cannot_be_set_from_the_admin_screen(): void
    {
        /*
         * The legacy edit form did not offer it either. An administrator
         * helping somebody locked out triggers a reset rather than setting a
         * credential they then know.
         */
        $player = Account::factory()->named('player')->withPassword('Original1!')->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson("/api/admin/accounts/{$player->account_id}", [
                'user_pass' => 'hacked',
                'password' => 'hacked',
            ])->assertStatus(422);

        $this->assertSame(
            'Original1!',
            DB::connection($this->loginConnection())->table('login')
                ->where('account_id', $player->account_id)->value('user_pass'),
        );
    }

    #[Test]
    public function staff_cannot_edit_an_account_at_or_above_their_own_rank(): void
    {
        /*
         * Without this the lowest-ranked person with the screen promotes
         * themselves to administrator in two steps, and the permission ladder
         * is decorative.
         */
        $target = Account::factory()->administrator()->named('boss')->create();

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->putJson("/api/admin/accounts/{$target->account_id}", ['email' => 'x@example.test'])
            ->assertStatus(403);

        $this->assertNotSame('x@example.test', $target->refresh()->email);
    }

    #[Test]
    public function staff_cannot_promote_an_account_past_themselves(): void
    {
        $player = Account::factory()->named('player')->create();

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->putJson("/api/admin/accounts/{$player->account_id}", ['group_id' => 99])
            ->assertStatus(403);

        $this->assertSame(0, (int) $player->refresh()->group_id);
    }

    #[Test]
    public function an_administrator_can_promote_within_their_rank(): void
    {
        $player = Account::factory()->named('player')->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson("/api/admin/accounts/{$player->account_id}", ['group_id' => 2])
            ->assertOk();

        $this->assertSame(2, (int) $player->refresh()->group_id);
    }

    #[Test]
    public function staff_cannot_edit_their_own_account_here(): void
    {
        // The normal account pages are the route for that, and they ask for
        // the password.
        $admin = Account::factory()->administrator()->create();

        $this->actingAs($admin)
            ->putJson("/api/admin/accounts/{$admin->account_id}", ['email' => 'x@example.test'])
            ->assertStatus(403);
    }

    #[Test]
    public function the_audit_columns_cannot_be_rewritten(): void
    {
        /*
         * logincount, lastlogin and last_ip are facts the emulator records.
         * Editing them would be falsifying an audit trail.
         */
        $player = Account::factory()->named('player')->state(['logincount' => 42])->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson("/api/admin/accounts/{$player->account_id}", [
                'logincount' => 0,
                'last_ip' => '0.0.0.0',
            ])->assertStatus(422);

        $this->assertSame(42, (int) $player->refresh()->logincount);
    }

    /*
    |--------------------------------------------------------------------------
    | The legacy status feed
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_status_xml_keeps_the_legacy_element_names(): void
    {
        /*
         * Server listing sites, Discord bots and forum widgets parse these
         * names. Renaming them would be the same as removing the endpoint.
         */
        $response = $this->get('/api/server/status.xml')->assertOk();

        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml);
        $this->assertSame('ServerStatus', $xml->getName());
        $this->assertNotEmpty($xml->Group);

        $server = $xml->Group[0]->Server[0];

        foreach (['name', 'loginServer', 'charServer', 'mapServer', 'playersOnline'] as $attribute) {
            $this->assertTrue(isset($server[$attribute]), "The `{$attribute}` attribute is part of the contract.");
        }
    }

    #[Test]
    public function the_status_xml_is_public(): void
    {
        $this->get('/api/server/status.xml')->assertOk();
        $this->assertGuest();
    }
}
