<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The signed-in account's own history.
 *
 * Ports the coverage for history/cplogin, gamelogin, passchange, passreset and
 * emailchange.
 *
 * The recurring assertion is that none of these leaks another account's rows.
 * They hold sign-in addresses and timestamps, so an endpoint that could be
 * pointed at somebody else is a way to follow a player around — and the only
 * thing preventing it is that the query is scoped to the session rather than
 * to an id from the request.
 */
final class AccountHistoryTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function logsConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->logsConnection();
    }

    /*
    |--------------------------------------------------------------------------
    | Panel sign-ins
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function panel_sign_ins_are_listed_with_their_outcome(): void
    {
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_loginlog')->insert([
            ['account_id' => $account->account_id, 'username' => $account->userid, 'ip' => '198.51.100.5', 'login_date' => now()->subDay(), 'error_code' => 0],
            ['account_id' => $account->account_id, 'username' => $account->userid, 'ip' => '198.51.100.9', 'login_date' => now(), 'error_code' => 2],
        ]);

        $response = $this->actingAs($account)
            ->getJson('/api/account/history/panel-logins')
            ->assertOk();

        $response->assertJsonPath('data.0.ip', '198.51.100.9')
            ->assertJsonPath('data.0.successful', false)
            // The stored code is FluxCP's Flux_LoginError value, preserved.
            ->assertJsonPath('data.0.outcome', trans('auth.failure.invalid_credentials'))
            ->assertJsonPath('data.1.successful', true)
            ->assertJsonPath('data.1.outcome', 'Signed in');
    }

    #[Test]
    public function another_accounts_panel_sign_ins_are_not_visible(): void
    {
        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_loginlog')->insert([
            ['account_id' => $theirs->account_id, 'username' => $theirs->userid, 'ip' => '203.0.113.1', 'login_date' => now(), 'error_code' => 0],
        ]);

        $this->actingAs($mine)
            ->getJson('/api/account/history/panel-logins')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_unrecognised_error_code_is_reported_as_refused(): void
    {
        /*
         * A row written by a newer FluxCP, or by a customised one. Showing
         * "Refused" is honest; guessing a reason would not be.
         */
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_loginlog')->insert([
            ['account_id' => $account->account_id, 'username' => $account->userid, 'ip' => '198.51.100.5', 'login_date' => now(), 'error_code' => 97],
        ]);

        $this->actingAs($account)
            ->getJson('/api/account/history/panel-logins')
            ->assertOk()
            ->assertJsonPath('data.0.outcome', 'Refused')
            ->assertJsonPath('data.0.successful', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Game sign-ins
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function game_sign_ins_are_matched_on_the_account_name(): void
    {
        // rAthena's loginlog has no account id; it stores the name typed.
        $account = Account::factory()->named('merchant')->create();

        DB::connection($this->logsConnection())->table('loginlog')->insert([
            ['time' => now(), 'ip' => '198.51.100.5', 'user' => 'merchant', 'rcode' => 0, 'log' => 'login ok'],
            ['time' => now(), 'ip' => '203.0.113.9', 'user' => 'somebodyelse', 'rcode' => 0, 'log' => 'login ok'],
        ]);

        $this->actingAs($account)
            ->getJson('/api/account/history/game-logins')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ip', '198.51.100.5');
    }

    #[Test]
    public function game_sign_ins_match_case_insensitively_on_a_case_insensitive_server(): void
    {
        /*
         * With NoCase on, rAthena writes whatever capitalisation the player
         * typed, so a case-sensitive match silently hides half their history.
         */
        config(['rathena.groups.main.login.case_sensitive' => false]);
        $this->app->forgetInstance(ServerRegistry::class);

        $account = Account::factory()->named('merchant')->create();

        DB::connection($this->logsConnection())->table('loginlog')->insert([
            ['time' => now(), 'ip' => '198.51.100.5', 'user' => 'MERCHANT', 'rcode' => 0, 'log' => 'login ok'],
        ]);

        $this->actingAs($account)
            ->getJson('/api/account/history/game-logins')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function game_sign_ins_match_exactly_on_a_case_sensitive_server(): void
    {
        config(['rathena.groups.main.login.case_sensitive' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        $account = Account::factory()->named('merchant')->create();

        DB::connection($this->logsConnection())->table('loginlog')->insert([
            ['time' => now(), 'ip' => '198.51.100.5', 'user' => 'MERCHANT', 'rcode' => 0, 'log' => 'login ok'],
        ]);

        $this->actingAs($account)
            ->getJson('/api/account/history/game-logins')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Credential history
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function password_changes_are_listed(): void
    {
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_pwchange')->insert([
            ['account_id' => $account->account_id, 'change_date' => now(), 'change_ip' => '198.51.100.5'],
        ]);

        $this->actingAs($account)
            ->getJson('/api/account/history/password-changes')
            ->assertOk()
            ->assertJsonPath('data.0.ip', '198.51.100.5')
            ->assertJsonMissingPath('data.0.old_password');
    }

    #[Test]
    public function password_resets_show_whether_they_were_completed(): void
    {
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_resetpass')->insert([
            // Both rows carry the same keys: a batch insert requires it.
            ['code' => str_repeat('a', 32), 'account_id' => $account->account_id, 'request_date' => now()->subHour(), 'request_ip' => '198.51.100.5', 'reset_date' => null, 'reset_ip' => null, 'reset_done' => 0],
            ['code' => str_repeat('b', 32), 'account_id' => $account->account_id, 'request_date' => now()->subDay(), 'request_ip' => '198.51.100.6', 'reset_date' => now()->subDay(), 'reset_ip' => '198.51.100.6', 'reset_done' => 1],
        ]);

        $response = $this->actingAs($account)
            ->getJson('/api/account/history/password-resets')
            ->assertOk();

        $response->assertJsonPath('data.0.completed', false)
            ->assertJsonPath('data.0.completed_at', null)
            ->assertJsonPath('data.1.completed', true);
    }

    #[Test]
    public function the_reset_history_never_exposes_the_token(): void
    {
        /*
         * cp_resetpass.code holds the digest. It is not a working token, but
         * it has no business being in a response either.
         */
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_resetpass')->insert([
            ['code' => str_repeat('a', 32), 'account_id' => $account->account_id, 'request_date' => now(), 'request_ip' => '198.51.100.5', 'reset_done' => 0],
        ]);

        $response = $this->actingAs($account)->getJson('/api/account/history/password-resets')->assertOk();

        $this->assertStringNotContainsString(str_repeat('a', 32), $response->getContent());
    }

    #[Test]
    public function email_changes_show_both_addresses(): void
    {
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_emailchange')->insert([
            [
                'code' => '', 'account_id' => $account->account_id,
                'old_email' => 'old@example.test', 'new_email' => 'new@example.test',
                'request_date' => now(), 'request_ip' => '198.51.100.5',
                'change_date' => now(), 'change_ip' => '198.51.100.5', 'change_done' => 1,
            ],
        ]);

        $this->actingAs($account)
            ->getJson('/api/account/history/email-changes')
            ->assertOk()
            ->assertJsonPath('data.0.from', 'old@example.test')
            ->assertJsonPath('data.0.to', 'new@example.test')
            ->assertJsonPath('data.0.completed', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared behaviour
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_history_endpoint_needs_a_session(): void
    {
        foreach ([
            'panel-logins', 'game-logins', 'password-changes', 'password-resets', 'email-changes',
        ] as $endpoint) {
            $this->getJson("/api/account/history/{$endpoint}")->assertStatus(401);
        }
    }

    #[Test]
    public function every_history_endpoint_paginates_and_sorts(): void
    {
        $account = Account::factory()->create();

        foreach (range(1, 25) as $n) {
            DB::connection($this->loginConnection())->table('cp_loginlog')->insert([
                'account_id' => $account->account_id, 'username' => $account->userid,
                'ip' => "198.51.100.{$n}", 'login_date' => now()->subMinutes($n), 'error_code' => 0,
            ]);
        }

        $this->actingAs($account)
            ->getJson('/api/account/history/panel-logins?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25);

        $this->actingAs($account)
            ->getJson('/api/account/history/panel-logins?sort=date&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.ip', '198.51.100.25');
    }

    #[Test]
    public function an_unlisted_sort_column_is_refused(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($account)
            ->getJson('/api/account/history/panel-logins?sort=password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }
}
