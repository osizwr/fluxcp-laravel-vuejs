<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\AuthenticateAccount;
use App\Enums\LoginFailure;
use App\Exceptions\LoginFailed;
use App\Models\Account;
use App\Models\PanelCredential;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Behaviour tests for the sign-in flow, checked against what FluxCP's
 * Flux_SessionData::login() does.
 *
 * The order of the checks is as much a part of the contract as the checks
 * themselves, so the refusal reason is asserted rather than just the fact of
 * refusal.
 */
final class AuthenticateAccountTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function authenticate(
        string $username,
        string $password,
        string $ip = '198.51.100.10',
        ?string $group = null,
    ): Account {
        return $this->app->make(AuthenticateAccount::class)
            ->handle($group, $username, $password, $ip);
    }

    private function assertRefusedBecause(LoginFailure $expected, callable $attempt): void
    {
        try {
            $attempt();
        } catch (LoginFailed $e) {
            $this->assertSame(
                $expected,
                $e->reason,
                "Expected refusal {$expected->name}, got {$e->reason->name}.",
            );

            return;
        }

        $this->fail("Expected sign-in to be refused with {$expected->name}, but it succeeded.");
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_authenticates_against_a_cleartext_rathena_password(): void
    {
        // rAthena's default: login.user_pass holds the password itself.
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $account = $this->authenticate('merchant', 'Zeny4Days!');

        $this->assertSame('merchant', $account->userid);
    }

    #[Test]
    public function it_authenticates_against_an_md5_rathena_password_when_the_server_uses_md5(): void
    {
        config(['rathena.groups.main.login.use_md5' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        Account::factory()->named('hashuser')->withMd5Password('Zeny4Days!')->create();

        $account = $this->authenticate('hashuser', 'Zeny4Days!');

        $this->assertSame('hashuser', $account->userid);
    }

    #[Test]
    public function it_refuses_a_wrong_password(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->assertRefusedBecause(
            LoginFailure::InvalidCredentials,
            fn () => $this->authenticate('merchant', 'wrong-password'),
        );
    }

    #[Test]
    public function it_refuses_an_account_that_does_not_exist(): void
    {
        $this->assertRefusedBecause(
            LoginFailure::InvalidCredentials,
            fn () => $this->authenticate('nobody', 'anything'),
        );
    }

    #[Test]
    public function it_refuses_an_unknown_server_group(): void
    {
        $this->assertRefusedBecause(
            LoginFailure::UnknownServer,
            fn () => $this->authenticate('merchant', 'whatever', group: 'no-such-group'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Accounts that are not players
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_an_rathena_inter_server_account(): void
    {
        // sex = 'S' marks the emulator's own account. Letting one sign in
        // would hand a visitor an account the panel treats as real.
        Account::factory()->named('s1')->withPassword('p')->serverAccount()->create();

        $this->assertRefusedBecause(
            LoginFailure::InvalidCredentials,
            fn () => $this->authenticate('s1', 'p'),
        );
    }

    #[Test]
    public function it_refuses_an_account_disabled_with_a_negative_group_id(): void
    {
        Account::factory()->named('retired')->withPassword('Zeny4Days!')->disabled()->create();

        $this->assertRefusedBecause(
            LoginFailure::InvalidCredentials,
            fn () => $this->authenticate('retired', 'Zeny4Days!'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bans
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_a_temporarily_banned_account(): void
    {
        Account::factory()->named('timeout')->withPassword('Zeny4Days!')
            ->temporarilyBanned(time() + 3600)->create();

        $this->assertRefusedBecause(
            LoginFailure::TemporarilyBanned,
            fn () => $this->authenticate('timeout', 'Zeny4Days!'),
        );
    }

    #[Test]
    public function it_clears_a_lapsed_temporary_ban_and_allows_the_sign_in(): void
    {
        // rAthena leaves unban_time populated after a ban expires. The panel
        // zeroes it, because the game server reads the same column and would
        // otherwise keep treating the account as banned.
        $account = Account::factory()->named('served')->withPassword('Zeny4Days!')
            ->temporarilyBanned(time() - 60)->create();

        $this->authenticate('served', 'Zeny4Days!');

        $this->assertSame(0, (int) $account->fresh()->unban_time);
    }

    #[Test]
    public function it_refuses_a_permanently_banned_account(): void
    {
        Account::factory()->named('cheater')->withPassword('Zeny4Days!')
            ->permanentlyBanned()->create();

        $this->assertRefusedBecause(
            LoginFailure::PermanentlyBanned,
            fn () => $this->authenticate('cheater', 'Zeny4Days!'),
        );
    }

    #[Test]
    public function it_reports_a_pending_confirmation_rather_than_a_ban_for_a_new_registration(): void
    {
        // An unconfirmed registration and a permanent ban share state 5. The
        // only thing telling them apart is an unconfirmed registration row,
        // so getting this wrong tells every new registrant they are banned.
        $account = Account::factory()->named('newcomer')->withPassword('Zeny4Days!')
            ->permanentlyBanned()->create();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->insert([
                'account_id' => $account->account_id,
                'userid' => $account->userid,
                'email' => $account->email,
                'sex' => 'M',
                'reg_date' => now(),
                'reg_ip' => '198.51.100.10',
                'confirmed' => 0,
            ]);

        $this->assertRefusedBecause(
            LoginFailure::PendingConfirmation,
            fn () => $this->authenticate('newcomer', 'Zeny4Days!'),
        );
    }

    #[Test]
    public function it_allows_a_banned_account_in_when_the_operator_permits_it(): void
    {
        config(['panel.login.allow_permanently_banned' => true]);

        Account::factory()->named('appealing')->withPassword('Zeny4Days!')
            ->permanentlyBanned()->create();

        $account = $this->authenticate('appealing', 'Zeny4Days!');

        $this->assertSame('appealing', $account->userid);
    }

    /*
    |--------------------------------------------------------------------------
    | IP bans
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_a_banned_address_before_checking_the_password(): void
    {
        // Reported ahead of the credential check so a banned address cannot
        // use the endpoint to discover which accounts exist.
        $this->banAddress('198.51.100.*');

        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->assertRefusedBecause(
            LoginFailure::IpBanned,
            fn () => $this->authenticate('merchant', 'totally-wrong', ip: '198.51.100.10'),
        );
    }

    #[Test]
    public function it_ignores_an_ip_ban_that_has_expired(): void
    {
        $this->banAddress('198.51.100.*', expiresAt: now()->subHour());

        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $account = $this->authenticate('merchant', 'Zeny4Days!', ip: '198.51.100.10');

        $this->assertSame('merchant', $account->userid);
    }

    #[Test]
    public function it_allows_a_banned_address_in_when_the_operator_permits_it(): void
    {
        config(['panel.login.allow_ip_banned' => true]);

        $this->banAddress('198.51.100.*');
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $account = $this->authenticate('merchant', 'Zeny4Days!', ip: '198.51.100.10');

        $this->assertSame('merchant', $account->userid);
    }

    private function banAddress(string $pattern, ?\DateTimeInterface $expiresAt = null): void
    {
        DB::connection($this->serverGroup()->loginConnection())
            ->table('ipbanlist')
            ->insert([
                'list' => $pattern,
                'btime' => now(),
                'rtime' => $expiresAt ?? now()->addDay(),
                'reason' => 'Test ban',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Panel credential upgrade (D1)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_stores_a_hashed_panel_credential_on_a_successful_sign_in(): void
    {
        $account = Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->authenticate('merchant', 'Zeny4Days!');

        $credential = PanelCredential::query()
            ->where('server_group', 'main')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertNotNull($credential, 'Expected a panel credential to be written.');
        $this->assertNotSame('Zeny4Days!', $credential->password_hash, 'The panel hash must not be the password.');
        $this->assertTrue(password_verify('Zeny4Days!', $credential->password_hash));
    }

    #[Test]
    public function it_leaves_the_rathena_password_column_untouched(): void
    {
        // The emulator's login server reads this column, so the panel must
        // never rewrite it on sign-in. This is the heart of D1.
        $account = Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->authenticate('merchant', 'Zeny4Days!');

        $stored = DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->value('user_pass');

        $this->assertSame('Zeny4Days!', $stored);
    }

    #[Test]
    public function it_prefers_the_panel_hash_once_one_exists(): void
    {
        $account = Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->authenticate('merchant', 'Zeny4Days!');

        // Scramble rAthena's copy. A sign-in that still succeeds proves the
        // panel verified against its own hash rather than the weak column.
        DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->update(['user_pass' => 'not-the-password']);

        $this->assertSame('merchant', $this->authenticate('merchant', 'Zeny4Days!')->userid);
    }

    /*
    |--------------------------------------------------------------------------
    | Password handling in audit tables (D2)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_never_writes_a_password_into_the_login_audit_table(): void
    {
        // FluxCP wrote the submitted password into cp_loginlog on every
        // attempt, successful or not. With cleartext storage that persisted
        // every password anyone ever typed into the form.
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->authenticate('merchant', 'Zeny4Days!');

        $recorded = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_loginlog')
            ->pluck('password')
            ->filter(fn (?string $value): bool => $value !== null && $value !== '');

        $this->assertTrue($recorded->isEmpty(), 'No password may be written to cp_loginlog.');
    }

    /*
    |--------------------------------------------------------------------------
    | Account name matching
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_the_account_name_case_insensitively_by_default(): void
    {
        // rAthena ships with NoCase on, which FluxCP mapped to a
        // case-insensitive comparison.
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->assertSame('merchant', $this->authenticate('MERCHANT', 'Zeny4Days!')->userid);
    }

    #[Test]
    public function it_matches_the_account_name_exactly_when_the_server_is_case_sensitive(): void
    {
        config(['rathena.groups.main.login.case_sensitive' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->assertRefusedBecause(
            LoginFailure::InvalidCredentials,
            fn () => $this->authenticate('MERCHANT', 'Zeny4Days!'),
        );
    }
}
