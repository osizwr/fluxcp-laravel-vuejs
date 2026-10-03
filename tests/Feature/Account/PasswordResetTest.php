<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetMail;
use App\Models\Account;
use App\Support\Tokens\SecureToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Password reset by e-mail.
 *
 * Ports the coverage for modules/account/resetpass.php and resetpw.php. Each
 * of the five defects listed on PasswordResetService has a test here, because
 * a comment saying a thing was fixed is not evidence that it was.
 */
final class PasswordResetTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldPassw0rd!';

    private const NEW_PASSWORD = 'BrandNewP4ss!';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config()->set('panel.password_reset.enabled', true);
        config()->set('panel.password_reset.expires_after_hours', 2);
        config()->set('panel.password_reset.blocked_at_or_above_level', 1);
    }

    private function player(string $username = 'forgetful'): Account
    {
        return Account::factory()
            ->named($username)
            ->withPassword(self::OLD_PASSWORD)
            ->state(['email' => $username.'@example.com'])
            ->create();
    }

    /**
     * Request a reset and return the token from the e-mail.
     *
     * Mail is faked once, in setUp, and this reads the most recent message.
     * Re-faking here would not work: Mail::fake() replaces the container's
     * mailer, but a request that has already run leaves the previous instance
     * resolved, so messages would go to the old fake and the new one would
     * look empty.
     */
    private function requestReset(Account $account): string
    {
        $this->postJson('/api/auth/password/forgot', [
            'username' => $account->userid,
            'email' => $account->email,
        ])->assertOk();

        return $this->tokenFromLatestResetMail();
    }

    private function tokenFromLatestResetMail(): string
    {
        $sent = Mail::sent(PasswordResetMail::class);

        $this->assertNotEmpty($sent, 'No password reset mail was sent.');

        preg_match('/token=([0-9a-f]{64})/', $sent->last()->render(), $matches);

        $this->assertArrayHasKey(1, $matches, 'No reset token was found in the sent mail.');

        return $matches[1];
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
    | Requesting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function requesting_a_reset_mails_a_link(): void
    {
        $account = $this->player();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'forgetful@example.com',
        ])->assertOk()->assertJsonPath('message', trans('accounts.reset.requested'));

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($account): bool {
            $mail->assertTo($account->email);

            $this->assertStringContainsString('/reset-password?token=', $mail->render());

            return true;
        });
    }

    #[Test]
    public function the_mail_never_contains_a_password(): void
    {
        /*
         * The legacy resetpw.php generated a password and e-mailed it in
         * cleartext. This is the test that says that does not happen. (D16)
         */
        $account = $this->player();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'forgetful@example.com',
        ])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($account): bool {
            $rendered = $mail->render();

            $this->assertStringNotContainsString(self::OLD_PASSWORD, $rendered);
            $this->assertStringNotContainsString($this->storedPassword($account), $rendered);

            return true;
        });
    }

    #[Test]
    public function the_request_is_recorded_without_any_password(): void
    {
        $account = $this->player();

        $this->requestReset($account);

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_resetpass')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertNotNull($row);
        // The legacy panel wrote the account's current password here. (D2)
        $this->assertSame('', $row->old_password);
        $this->assertSame('', (string) $row->new_password);
    }

    #[Test]
    public function the_token_is_stored_as_a_digest(): void
    {
        $account = $this->player();

        $token = $this->requestReset($account);

        $stored = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_resetpass')
            ->where('account_id', $account->account_id)
            ->value('code');

        $this->assertNotSame($token, $stored);
        $this->assertSame(SecureToken::digestOf($token), $stored);
    }

    #[Test]
    public function the_stored_digest_cannot_be_used_as_a_token(): void
    {
        $account = $this->player();

        $this->requestReset($account);

        $digest = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_resetpass')
            ->where('account_id', $account->account_id)
            ->value('code');

        /*
         * Somebody with read access to the database must not be able to reset
         * passwords with what they find in it. In the legacy panel they could:
         * the column held the code that was e-mailed.
         */
        $this->postJson('/api/auth/password/reset', [
            'token' => $digest,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertSame(self::OLD_PASSWORD, $this->storedPassword($account->refresh()));
    }

    #[Test]
    public function the_answer_is_the_same_whether_or_not_the_account_exists(): void
    {
        /*
         * The legacy panel showed ResetPassFailed only when the details did
         * not match, which made the form a way to test whether an address was
         * registered.
         */
        $this->player();

        $expected = trans('accounts.reset.requested');

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'forgetful@example.com',
        ])->assertOk()->assertJsonPath('message', $expected);

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'nobody',
            'email' => 'nobody@example.com',
        ])->assertOk()->assertJsonPath('message', $expected);

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'wrong@example.com',
        ])->assertOk()->assertJsonPath('message', $expected);
    }

    #[Test]
    public function a_request_with_the_wrong_address_sends_nothing(): void
    {
        $this->player();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'attacker@example.com',
        ])->assertOk();

        Mail::assertNotSent(PasswordResetMail::class);
    }

    #[Test]
    public function a_new_request_retires_the_previous_one(): void
    {
        $account = $this->player();

        $first = $this->requestReset($account);
        $second = $this->requestReset($account);

        $this->assertNotSame($first, $second);

        // Only the newest link works, so asking twice does not double the
        // number of ways in.
        $this->postJson('/api/auth/password/reset', [
            'token' => $first,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->postJson('/api/auth/password/reset', [
            'token' => $second,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Completing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_valid_token_sets_the_chosen_password_in_both_stores(): void
    {
        $account = $this->player();
        $token = $this->requestReset($account);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('message', trans('accounts.reset.complete'));

        // rAthena's own column, in rAthena's own format.
        $this->assertSame(self::NEW_PASSWORD, $this->storedPassword($account->refresh()));

        // And the panel's hash, so the website accepts it too.
        $account->unsetRelation('panelCredential');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $account->panelCredential?->password_hash));

        $this->postJson('/api/auth/login', [
            'username' => 'forgetful',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function the_old_password_stops_working(): void
    {
        $account = $this->player();
        $token = $this->requestReset($account);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'username' => 'forgetful',
            'password' => self::OLD_PASSWORD,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_token_can_only_be_used_once(): void
    {
        $account = $this->player();
        $token = $this->requestReset($account);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => 'YetAnotherP4ss!',
            'password_confirmation' => 'YetAnotherP4ss!',
        ])->assertStatus(422)->assertJsonPath('message', trans('accounts.reset.invalid'));

        $this->assertSame(self::NEW_PASSWORD, $this->storedPassword($account->refresh()));
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        /*
         * The legacy resetpw.php never looked at request_date, so a link from
         * a mailbox compromised years later still worked.
         */
        $account = $this->player();
        $token = $this->requestReset($account);

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_resetpass')
            ->where('account_id', $account->account_id)
            ->update(['request_date' => now()->subHours(3)]);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertSame(self::OLD_PASSWORD, $this->storedPassword($account->refresh()));
    }

    #[Test]
    public function a_rejected_password_leaves_the_link_usable(): void
    {
        $account = $this->player();
        $token = $this->requestReset($account);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        /*
         * The token is retired only once a password has actually been
         * accepted. Burning it on a password that failed the policy would mean
         * requesting a whole new link to fix a typo.
         */
        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function the_change_is_announced_to_the_account_holder(): void
    {
        $account = $this->player();
        $token = $this->requestReset($account);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        Mail::assertSent(PasswordChangedMail::class, function (PasswordChangedMail $mail) use ($account): bool {
            // To the address on the account, so an attacker who completed the
            // reset cannot also choose who hears about it.
            $mail->assertTo($account->email);

            $this->assertStringNotContainsString(self::NEW_PASSWORD, $mail->render());

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accounts that may not be reset this way
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function staff_accounts_cannot_be_reset_by_email(): void
    {
        /*
         * The legacy NoResetPassGroupLevel setting. Holding a game master's
         * mailbox should not be enough to take their account.
         */
        Account::factory()
            ->named('gamemaster')
            ->juniorGameMaster()
            ->withPassword(self::OLD_PASSWORD)
            ->state(['email' => 'gm@example.com'])
            ->create();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'gamemaster',
            'email' => 'gm@example.com',
        ])
            ->assertOk()
            // Answered as for any other request, so the form cannot be used to
            // find out which accounts belong to staff.
            ->assertJsonPath('message', trans('accounts.reset.requested'));

        Mail::assertNotSent(PasswordResetMail::class);
    }

    #[Test]
    public function a_banned_account_cannot_be_reset(): void
    {
        Account::factory()
            ->named('banned')
            ->permanentlyBanned()
            ->withPassword(self::OLD_PASSWORD)
            ->state(['email' => 'banned@example.com'])
            ->create();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'banned',
            'email' => 'banned@example.com',
        ])->assertOk();

        Mail::assertNotSent(PasswordResetMail::class);
    }

    #[Test]
    public function a_server_account_cannot_be_reset(): void
    {
        Account::factory()
            ->named('s1')
            ->serverAccount()
            ->withPassword(self::OLD_PASSWORD)
            ->state(['email' => 's1@example.com'])
            ->create();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 's1',
            'email' => 's1@example.com',
        ])->assertOk();

        Mail::assertNotSent(PasswordResetMail::class);
    }

    #[Test]
    public function a_promotion_to_staff_invalidates_an_outstanding_link(): void
    {
        $account = $this->player();
        $token = $this->requestReset($account);

        // Re-checked at completion, not only when the link was issued.
        DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->update(['group_id' => 99]);

        $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertSame(self::OLD_PASSWORD, $this->storedPassword($account->refresh()));
    }

    #[Test]
    public function the_whole_flow_can_be_turned_off(): void
    {
        config()->set('panel.password_reset.enabled', false);

        $this->player();

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'forgetful@example.com',
        ])->assertStatus(403);
    }

    #[Test]
    public function requests_are_throttled_per_account(): void
    {
        $this->player();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/password/forgot', [
                'username' => 'forgetful',
                'email' => 'forgetful@example.com',
            ])->assertOk();
        }

        $this->postJson('/api/auth/password/forgot', [
            'username' => 'forgetful',
            'email' => 'forgetful@example.com',
        ])->assertStatus(429);
    }
}
