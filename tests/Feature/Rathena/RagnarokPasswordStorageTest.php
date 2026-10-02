<?php

declare(strict_types=1);

namespace Tests\Feature\Rathena;

use App\Enums\Gender;
use App\Models\Account;
use App\Models\PanelCredential;
use App\Services\Rathena\RathenaAccountService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * What ends up in `login.user_pass`.
 *
 * These are the regression tests that matter most in this codebase, because the
 * failure they guard against is silent: an account whose password went through
 * Laravel's hasher is created successfully, looks correct in the panel, and
 * simply cannot log in to the game. Nothing surfaces until a player complains.
 *
 * The expected format is not a preference. From rAthena's source:
 *
 *   src/common/mmo.hpp     `#define PASSWD_LENGTH (32 + 1)`
 *   src/login/account.hpp  `char pass[32+1];  // 23+1 for plaintext,
 *                          32+1 for md5-ed passwords`
 *   src/login/login.cpp    login_check_password() compares with
 *                          `strcmp(sd.passwd, acc.pass)`, and under
 *                          passwordencrypt MD5s `acc.pass` itself with a
 *                          session key -- so the stored value has to be
 *                          reproducible by the client.
 *
 * A 60-character bcrypt hash therefore cannot work: it does not fit, it is not
 * reproducible, and it would itself be hashed. See MIGRATION_DECISIONS.md (D1).
 */
final class RagnarokPasswordStorageTest extends TestCase
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

    private function createTestAccount(string $username = 'fluxcp_test'): Account
    {
        return $this->service()->create(
            username: $username,
            password: self::PASSWORD,
            email: "{$username}@example.com",
            gender: Gender::Male,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The core guarantee
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_never_stores_a_laravel_hash_in_the_rathena_password_column(): void
    {
        $account = $this->createTestAccount();
        $stored = $this->storedPassword($account->account_id);

        foreach (['$2y$', '$2a$', '$2b$', '$argon2i$', '$argon2id$'] as $prefix) {
            $this->assertStringNotContainsString(
                $prefix,
                $stored,
                "login.user_pass must never contain a {$prefix} hash."
            );
        }

        // Laravel's own detector agrees it is not one of its hashes.
        $this->assertFalse(
            Hash::isHashed($stored),
            'login.user_pass must not be a value produced by Laravel\'s hasher.'
        );

        /*
         * Laravel does not merely fail to match the value, it refuses to
         * examine it: BcryptHasher::check() throws when handed something that
         * is not one of its own hashes. That is a stronger guarantee than a
         * false return, so it is asserted as the throw it is.
         */
        try {
            Hash::check(self::PASSWORD, $stored);
            $this->fail('Laravel accepted the rAthena credential as one of its hashes.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not use the Bcrypt algorithm', $e->getMessage());
        }
    }

    #[Test]
    public function the_stored_value_fits_the_column_the_emulator_reads(): void
    {
        // PASSWD_LENGTH is (32 + 1) and the column is varchar(32). A longer
        // value is truncated on write and can then never be matched.
        $account = $this->createTestAccount();

        $this->assertLessThanOrEqual(32, strlen($this->storedPassword($account->account_id)));
    }

    #[Test]
    public function it_stores_the_password_verbatim_when_the_server_does_not_use_md5(): void
    {
        // rAthena's default: login_config.use_md5_passwds = false, and
        // login_mmo_auth_new() stores the client-supplied password as-is.
        $this->assertFalse($this->serverGroup()->loginServer->usesMd5);

        $account = $this->createTestAccount();

        $this->assertSame(self::PASSWORD, $this->storedPassword($account->account_id));
    }

    #[Test]
    public function it_stores_an_md5_digest_when_the_server_uses_md5(): void
    {
        config(['rathena.groups.main.login.use_md5' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        $account = $this->createTestAccount('fluxcp_md5');
        $stored = $this->storedPassword($account->account_id);

        $this->assertSame(md5(self::PASSWORD), $stored);
        $this->assertSame(32, strlen($stored));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stored);
        $this->assertFalse(Hash::isHashed($stored));
    }

    #[Test]
    public function it_rejects_a_password_too_long_for_the_column(): void
    {
        // Silent truncation would create an account that cannot log in
        // anywhere, so this is a hard storage constraint rather than policy.
        config(['panel.registration.password.max_length' => 200]);

        $this->expectException(ValidationException::class);

        $this->service()->create(
            username: 'fluxcp_long',
            password: str_repeat('a1B!', 20),
            email: 'long@example.com',
            gender: Gender::Male,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | No cast or mutator may interfere
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_account_model_declares_no_cast_on_the_password_column(): void
    {
        // The structural assertion. A 'hashed' cast added here later would
        // break game login, and this is what catches it.
        $casts = (new Account)->getCasts();

        $this->assertArrayNotHasKey('user_pass', $casts);
        $this->assertNotContains('hashed', $casts);
    }

    #[Test]
    public function the_account_model_has_no_password_mutator(): void
    {
        $this->assertFalse(
            method_exists(Account::class, 'setUserPassAttribute'),
            'A mutator on user_pass would transform the credential invisibly.'
        );
    }

    #[Test]
    public function assigning_the_column_directly_stores_exactly_what_was_assigned(): void
    {
        // Proves no cast, mutator, observer or model event rewrites the value
        // on the way to the database.
        $account = Account::factory()->create();
        $marker = 'rawValue123';

        $account->forceFill(['user_pass' => $marker])->save();

        $this->assertSame($marker, $this->storedPassword($account->account_id));
    }

    /*
    |--------------------------------------------------------------------------
    | The panel's own credential is the opposite case
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_panel_credential_is_hashed_with_laravels_hasher(): void
    {
        // The separation only holds if the panel side really is hashed. If
        // this fails, the two systems have been collapsed into one.
        $account = $this->createTestAccount();

        $credential = PanelCredential::query()
            ->where('server_group', 'main')
            ->where('account_id', $account->account_id)
            ->firstOrFail();

        $this->assertTrue(Hash::isHashed($credential->password_hash));
        $this->assertTrue(Hash::check(self::PASSWORD, $credential->password_hash));
        $this->assertNotSame(self::PASSWORD, $credential->password_hash);
    }

    #[Test]
    public function the_two_credentials_are_stored_in_different_formats(): void
    {
        $account = $this->createTestAccount();

        $game = $this->storedPassword($account->account_id);
        $panel = PanelCredential::query()
            ->where('account_id', $account->account_id)
            ->value('password_hash');

        $this->assertNotSame($game, $panel, 'The two systems must not share a stored value.');
        $this->assertFalse(Hash::isHashed($game));
        $this->assertTrue(Hash::isHashed((string) $panel));
    }

    /*
    |--------------------------------------------------------------------------
    | Password changes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_a_password_keeps_the_rathena_format(): void
    {
        $account = $this->createTestAccount();

        $this->service()->changePassword($account, 'Replacement456!');

        $stored = $this->storedPassword($account->account_id);

        $this->assertSame('Replacement456!', $stored);
        $this->assertFalse(Hash::isHashed($stored));
    }

    #[Test]
    public function changing_a_password_also_refreshes_the_panel_hash(): void
    {
        $account = $this->createTestAccount();

        $this->service()->changePassword($account, 'Replacement456!');

        $panel = (string) PanelCredential::query()
            ->where('account_id', $account->account_id)
            ->value('password_hash');

        $this->assertTrue(Hash::check('Replacement456!', $panel));
        $this->assertFalse(Hash::check(self::PASSWORD, $panel));
    }
}
