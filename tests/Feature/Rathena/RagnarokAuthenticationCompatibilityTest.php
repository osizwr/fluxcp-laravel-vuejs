<?php

declare(strict_types=1);

namespace Tests\Feature\Rathena;

use App\Actions\Auth\AuthenticateAccount;
use App\Enums\Gender;
use App\Models\Account;
use App\Models\PanelCredential;
use App\Services\Rathena\RathenaAccountService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * That an account created here can actually be used.
 *
 * ---------------------------------------------------------------------------
 * What these tests can and cannot prove
 * ---------------------------------------------------------------------------
 *
 * They cannot log in to a game server. That needs a running rAthena login
 * server and a Ragnarok client, neither of which exists in this environment, so
 * claiming a successful game login would be a fabrication.
 *
 * What they do instead is reproduce the emulator's comparison exactly, from its
 * source, and assert the stored value satisfies it:
 *
 *   src/login/login.cpp, login_check_password()
 *       if( sd.passwdenc == 0 ){
 *           return 0 == strcmp( sd.passwd, acc.pass );
 *       }
 *
 * `strcmp` is a byte-for-byte comparison of what the client transmits against
 * the stored column. So the check below -- that the column equals the exact
 * byte string the client would send -- is the same condition the login server
 * applies. A real game login remains an operator verification step, and is
 * listed as such in the report.
 */
final class RagnarokAuthenticationCompatibilityTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const PASSWORD = 'TestPassword123!';

    private function service(): RathenaAccountService
    {
        return $this->app->make(RathenaAccountService::class);
    }

    private function storedPassword(int $accountId): string
    {
        return (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $accountId)
            ->value('user_pass');
    }

    /**
     * What the Ragnarok client transmits for a given typed password.
     *
     * With use_MD5_passwords off the client sends the password itself; with it
     * on the client sends the MD5 digest. Either way the login server compares
     * that string to the column with strcmp.
     */
    private function whatTheClientWouldSend(string $typed): string
    {
        return $this->serverGroup()->loginServer->usesMd5 ? md5($typed) : $typed;
    }

    /**
     * rAthena's `strcmp(sd.passwd, acc.pass) == 0`.
     */
    private function rathenaWouldAccept(string $typed, string $stored): bool
    {
        return strcmp($this->whatTheClientWouldSend($typed), $stored) === 0;
    }

    /*
    |--------------------------------------------------------------------------
    | The emulator's comparison
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_stored_credential_satisfies_rathenas_comparison(): void
    {
        $account = $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $this->assertTrue(
            $this->rathenaWouldAccept(self::PASSWORD, $this->storedPassword($account->account_id)),
            "rAthena's login server would reject this account's own password.",
        );
    }

    #[Test]
    public function it_satisfies_the_comparison_on_a_server_that_uses_md5(): void
    {
        config(['rathena.groups.main.login.use_md5' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        $account = $this->service()->create(
            username: 'fluxcp_md5',
            password: self::PASSWORD,
            email: 'md5@example.com',
            gender: Gender::Male,
        );

        $this->assertTrue(
            $this->rathenaWouldAccept(self::PASSWORD, $this->storedPassword($account->account_id)),
        );
    }

    #[Test]
    public function the_comparison_rejects_a_wrong_password(): void
    {
        // Guards the check itself: a test that accepted anything would pass
        // the one above for the wrong reason.
        $account = $this->service()->create(
            username: 'fluxcp_wrong',
            password: self::PASSWORD,
            email: 'wrong@example.com',
            gender: Gender::Male,
        );

        $this->assertFalse(
            $this->rathenaWouldAccept('NotThePassword1!', $this->storedPassword($account->account_id)),
        );
    }

    #[Test]
    public function a_bcrypt_hash_would_not_satisfy_the_comparison(): void
    {
        // Demonstrates the consequence of the mistake these tests exist to
        // prevent, rather than only asserting its absence.
        $bcrypt = password_hash(self::PASSWORD, PASSWORD_BCRYPT);

        $this->assertFalse(
            $this->rathenaWouldAccept(self::PASSWORD, $bcrypt),
            'A bcrypt hash in login.user_pass would lock the account out of the game.',
        );

        $this->assertGreaterThan(
            32,
            strlen($bcrypt),
            'It would not even fit the column: PASSWD_LENGTH is (32 + 1).',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The panel
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_account_created_here_can_sign_in_to_the_panel(): void
    {
        $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $authenticated = $this->app->make(AuthenticateAccount::class)
            ->handle(null, 'fluxcp_test', self::PASSWORD, '198.51.100.10');

        $this->assertSame('fluxcp_test', $authenticated->userid);
    }

    #[Test]
    public function an_account_created_here_can_sign_in_over_http(): void
    {
        $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $this->postJson('/api/auth/login', [
            'username' => 'fluxcp_test',
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.username', 'fluxcp_test');

        $this->assertAuthenticated();
    }

    #[Test]
    public function the_panel_rejects_the_wrong_password_for_a_created_account(): void
    {
        $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $this->postJson('/api/auth/login', [
            'username' => 'fluxcp_test',
            'password' => 'NotThePassword1!',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function a_changed_password_works_in_both_systems(): void
    {
        $account = $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $this->service()->changePassword($account, 'Replacement456!');

        // The game would accept it.
        $this->assertTrue(
            $this->rathenaWouldAccept('Replacement456!', $this->storedPassword($account->account_id)),
        );
        $this->assertFalse(
            $this->rathenaWouldAccept(self::PASSWORD, $this->storedPassword($account->account_id)),
        );

        // And so does the panel.
        $this->postJson('/api/auth/login', [
            'username' => 'fluxcp_test',
            'password' => 'Replacement456!',
        ])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Existing accounts are left alone
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_account_that_predates_this_panel_still_authenticates(): void
    {
        // Created the way rAthena itself would: a bare cleartext credential and
        // no panel credential at all.
        $existing = Account::factory()->named('old_timer')->withPassword('LegacyPass1!')->create();

        $this->assertDatabaseMissing(
            'panel_credentials',
            ['account_id' => $existing->account_id],
        );

        $authenticated = $this->app->make(AuthenticateAccount::class)
            ->handle(null, 'old_timer', 'LegacyPass1!', '198.51.100.10');

        $this->assertSame($existing->account_id, $authenticated->account_id);
    }

    #[Test]
    public function an_existing_md5_account_still_authenticates(): void
    {
        config(['rathena.groups.main.login.use_md5' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        Account::factory()->named('old_md5')->withMd5Password('LegacyPass1!')->create();

        $authenticated = $this->app->make(AuthenticateAccount::class)
            ->handle(null, 'old_md5', 'LegacyPass1!', '198.51.100.10');

        $this->assertSame('old_md5', $authenticated->userid);
    }

    #[Test]
    public function signing_in_never_rewrites_the_rathena_credential(): void
    {
        // The guarantee that makes this port safe to point at a live server:
        // the emulator's column is read, never written, during a sign-in.
        $existing = Account::factory()->named('old_timer')->withPassword('LegacyPass1!')->create();
        $before = $this->storedPassword($existing->account_id);

        $this->app->make(AuthenticateAccount::class)
            ->handle(null, 'old_timer', 'LegacyPass1!', '198.51.100.10');

        $this->assertSame(
            $before,
            $this->storedPassword($existing->account_id),
            'login.user_pass must be byte-for-byte unchanged after a sign-in.',
        );
    }

    #[Test]
    public function creating_an_account_does_not_touch_any_other_accounts_credential(): void
    {
        $untouched = Account::factory()->named('bystander')->withPassword('Bystander1!')->create();
        $before = $this->storedPassword($untouched->account_id);

        $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $this->assertSame($before, $this->storedPassword($untouched->account_id));
        $this->assertDatabaseMissing(
            'panel_credentials',
            ['account_id' => $untouched->account_id],
        );
    }

    #[Test]
    public function no_migration_touches_an_rathena_table(): void
    {
        /*
         * The panel's migrations must only ever create its own tables. One
         * that rewrote rAthena's credential column would be unrecoverable, and
         * the project's instructions forbid it outright.
         *
         * Only string literals are inspected, via the tokeniser: searching the
         * raw text matches the word in a comment and makes the test fail for
         * the wrong reason.
         */
        $files = glob(database_path('migrations/*.php')) ?: [];

        $this->assertNotEmpty($files, 'Expected to find migrations to inspect.');

        foreach ($files as $file) {
            $literals = [];

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = trim($token[1], "'\"");
                }
            }

            $this->assertNotContains('user_pass', $literals, basename($file));

            foreach (['login', 'char', 'guild', 'guild_member', 'ipbanlist'] as $rathenaTable) {
                $this->assertNotContains(
                    $rathenaTable,
                    $literals,
                    basename($file)." refers to the rAthena table '{$rathenaTable}'.",
                );
            }

            // Every table name a migration does mention should be a panel one.
            foreach ($literals as $literal) {
                if (str_starts_with($literal, 'cp_')) {
                    $this->fail(basename($file)." refers to the panel-owned rAthena table '{$literal}'; "
                        .'those are installed by panel:install-schema, not migrated.');
                }
            }
        }
    }

    #[Test]
    public function the_panel_credential_table_is_the_only_place_a_hash_is_stored(): void
    {
        $account = $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
        );

        $this->assertSame(
            1,
            PanelCredential::query()->where('account_id', $account->account_id)->count(),
        );

        // And it lives on the application connection, not rAthena's.
        $this->assertSame(
            config('database.default'),
            (new PanelCredential)->getConnectionName(),
        );
    }
}
