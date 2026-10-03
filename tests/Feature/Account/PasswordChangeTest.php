<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Mail\PasswordChangedMail;
use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Changing the signed-in account's password.
 *
 * Ports the coverage for modules/account/changepass.php.
 */
final class PasswordChangeTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldPassw0rd!';

    private const NEW_PASSWORD = 'BrandNewP4ss!';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function signedIn(?Account $account = null): Account
    {
        $account ??= Account::factory()
            ->named('player')
            ->withPassword(self::OLD_PASSWORD)
            ->state(['email' => 'player@example.com'])
            ->create();

        $this->postJson('/api/auth/login', [
            'username' => $account->userid,
            'password' => self::OLD_PASSWORD,
        ])->assertOk();

        return $account;
    }

    private function storedPassword(Account $account): string
    {
        return (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->value('user_pass');
    }

    /*
    |--------------------------------------------------------------------------
    | Changing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_password_changes_in_both_stores(): void
    {
        $account = $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('message', trans('accounts.password.changed'));

        $this->assertSame(self::NEW_PASSWORD, $this->storedPassword($account->refresh()));

        $account->unsetRelation('panelCredential');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $account->panelCredential?->password_hash));
    }

    #[Test]
    public function the_change_is_recorded_without_the_passwords(): void
    {
        $account = $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_pwchange')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertNotNull($row);
        // The legacy panel wrote both the old and the new password here. (D2)
        $this->assertSame('', $row->old_password);
        $this->assertSame('', (string) $row->new_password);
    }

    #[Test]
    public function the_account_stays_signed_in_after_changing_its_password(): void
    {
        $this->signedIn();

        $response = $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        /*
         * The legacy panel signed the account holder out and left every other
         * session alone, which is backwards: changing a password is most often
         * a response to somebody else having access. See D18.
         */
        $this->assertAuthenticated();
        $this->getJson('/api/account')->assertOk();

        /*
         * Whether other sessions could be ended is reported rather than
         * assumed, because it depends on the session driver. This suite runs
         * with the array driver, where there is no store to query -- so the
         * honest answer here is false, and the revocation itself is covered in
         * SessionRevocationTest against the database driver.
         */
        $this->assertIsBool($response->json('other_sessions_revoked'));
    }

    #[Test]
    public function the_old_password_no_longer_signs_in(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/login', [
            'username' => 'player',
            'password' => self::OLD_PASSWORD,
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', [
            'username' => 'player',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function the_account_holder_is_notified(): void
    {
        $account = $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        Mail::assertSent(PasswordChangedMail::class, function (PasswordChangedMail $mail) use ($account): bool {
            $mail->assertTo($account->email);

            $rendered = $mail->render();

            $this->assertStringNotContainsString(self::NEW_PASSWORD, $rendered);
            $this->assertStringNotContainsString(self::OLD_PASSWORD, $rendered);

            return true;
        });
    }

    #[Test]
    public function an_md5_account_keeps_its_md5_format(): void
    {
        config(['rathena.groups.main.login.use_md5' => true]);
        $this->app->forgetInstance(ServerRegistry::class);

        $account = Account::factory()
            ->named('hashed')
            ->withMd5Password(self::OLD_PASSWORD)
            ->state(['email' => 'hashed@example.com'])
            ->create();

        $this->signedIn($account);

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        /*
         * The emulator's format, not the panel's. Writing cleartext to a
         * server running use_MD5_passwords locks the account out of the game.
         */
        $this->assertSame(md5(self::NEW_PASSWORD), $this->storedPassword($account->refresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_cannot_change_a_password(): void
    {
        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(401);
    }

    #[Test]
    public function the_wrong_current_password_is_refused(): void
    {
        $account = $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => 'NotMyPassword1!',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame(self::OLD_PASSWORD, $this->storedPassword($account->refresh()));
    }

    #[Test]
    public function mismatched_new_passwords_are_refused(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'DifferentP4ss!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function reusing_the_current_password_is_refused(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::OLD_PASSWORD,
            'password_confirmation' => self::OLD_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function a_weak_new_password_is_refused(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function staff_accounts_get_the_stricter_policy(): void
    {
        /*
         * The legacy panel applied this the wrong way round: changepass.php
         * used `group_level < EnableGMPassSecurity`, which gave ordinary
         * players the strict rules and game masters the loose ones. See D17.
         */
        config()->set('panel.registration.password.min_length', 8);
        config()->set('panel.registration.password.staff.applies_at_or_above_level', 1);
        config()->set('panel.registration.password.staff.min_length', 16);

        $staff = Account::factory()
            ->named('gamemaster')
            ->administrator()
            ->withPassword(self::OLD_PASSWORD)
            ->state(['email' => 'gm@example.com'])
            ->create();

        $this->signedIn($staff);

        // Long enough for a player, not for staff.
        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'Short1Pass!',
            'password_confirmation' => 'Short1Pass!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'MuchLongerStaffP4ss!',
            'password_confirmation' => 'MuchLongerStaffP4ss!',
        ])->assertOk();
    }

    #[Test]
    public function a_player_is_not_held_to_the_staff_policy(): void
    {
        config()->set('panel.registration.password.min_length', 8);
        config()->set('panel.registration.password.staff.applies_at_or_above_level', 1);
        config()->set('panel.registration.password.staff.min_length', 16);

        $this->signedIn();

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'Short1Pass!',
            'password_confirmation' => 'Short1Pass!',
        ])->assertOk();
    }

    #[Test]
    public function attempts_are_throttled(): void
    {
        $this->signedIn();

        for ($i = 0; $i < 10; $i++) {
            $this->putJson('/api/account/password', [
                'current_password' => 'WrongPassword'.$i.'!',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertStatus(422);
        }

        $this->putJson('/api/account/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(429);
    }
}
