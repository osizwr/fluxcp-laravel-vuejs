<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Mail\EmailChangeConfirmationMail;
use App\Models\Account;
use App\Support\Tokens\SecureToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Changing the e-mail address on an account.
 *
 * Ports the coverage for modules/account/changemail.php and confirmemail.php.
 */
final class EmailChangeTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const PASSWORD = 'MyPassw0rd!';

    private const OLD_EMAIL = 'old@example.com';

    private const NEW_EMAIL = 'new@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config()->set('panel.email_change.require_confirmation', true);
        config()->set('panel.email_change.expires_after_hours', 24);
        config()->set('panel.registration.allow_duplicate_emails', false);
    }

    private function signedIn(): Account
    {
        $account = Account::factory()
            ->named('player')
            ->withPassword(self::PASSWORD)
            ->state(['email' => self::OLD_EMAIL])
            ->create();

        $this->postJson('/api/auth/login', [
            'username' => 'player',
            'password' => self::PASSWORD,
        ])->assertOk();

        return $account;
    }

    private function storedEmail(Account $account): string
    {
        return (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->value('email');
    }

    private function tokenFromLatestMail(): string
    {
        $sent = Mail::sent(EmailChangeConfirmationMail::class);

        $this->assertNotEmpty($sent, 'No e-mail change confirmation was sent.');

        preg_match('/token=([0-9a-f]{64})/', $sent->last()->render(), $matches);

        $this->assertArrayHasKey(1, $matches, 'No token was found in the sent mail.');

        return $matches[1];
    }

    private function requestChange(string $email = self::NEW_EMAIL): string
    {
        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => $email,
            'email_confirmation' => $email,
        ])->assertOk();

        return $this->tokenFromLatestMail();
    }

    /*
    |--------------------------------------------------------------------------
    | With confirmation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function requesting_a_change_does_not_move_the_address_yet(): void
    {
        $account = $this->signedIn();

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            // Still the old one, so the client does not show a change that has
            // not happened.
            ->assertJsonPath('email', self::OLD_EMAIL);

        /*
         * The address on the account is what password reset trusts. It must
         * not move until the new one has been proven reachable.
         */
        $this->assertSame(self::OLD_EMAIL, $this->storedEmail($account->refresh()));
    }

    #[Test]
    public function the_confirmation_goes_to_the_proposed_address(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])->assertOk();

        Mail::assertSent(EmailChangeConfirmationMail::class, function (EmailChangeConfirmationMail $mail): bool {
            // The new address, not the current one. That is the whole point of
            // the step.
            $mail->assertTo(self::NEW_EMAIL);

            $this->assertStringContainsString('/confirm-email?token=', $mail->render());

            return true;
        });
    }

    #[Test]
    public function following_the_link_moves_the_address(): void
    {
        $account = $this->signedIn();
        $token = $this->requestChange();

        $this->postJson('/api/account/email/confirm', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('email', self::NEW_EMAIL);

        $this->assertSame(self::NEW_EMAIL, $this->storedEmail($account->refresh()));
    }

    #[Test]
    public function the_change_is_recorded_with_both_addresses(): void
    {
        $account = $this->signedIn();
        $token = $this->requestChange();

        $this->postJson('/api/account/email/confirm', ['token' => $token])->assertOk();

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_emailchange')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertSame(self::OLD_EMAIL, $row->old_email);
        $this->assertSame(self::NEW_EMAIL, $row->new_email);
        $this->assertSame(1, (int) $row->change_done);
        // Cleared, so a used token is no longer matchable.
        $this->assertSame('', $row->code);
    }

    #[Test]
    public function the_token_is_stored_as_a_digest(): void
    {
        $account = $this->signedIn();
        $token = $this->requestChange();

        $stored = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_emailchange')
            ->where('account_id', $account->account_id)
            ->value('code');

        $this->assertNotSame($token, $stored);
        $this->assertSame(SecureToken::digestOf($token), $stored);
    }

    #[Test]
    public function a_token_can_only_be_used_once(): void
    {
        $this->signedIn();
        $token = $this->requestChange();

        $this->postJson('/api/account/email/confirm', ['token' => $token])->assertOk();

        $this->postJson('/api/account/email/confirm', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('accounts.email.invalid'));
    }

    #[Test]
    public function an_expired_request_is_refused(): void
    {
        /*
         * The legacy confirmemail.php looked up cp_emailchange by code and
         * change_done only, so request_date was written and never read and a
         * link never stopped working.
         */
        $account = $this->signedIn();
        $token = $this->requestChange();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_emailchange')
            ->where('account_id', $account->account_id)
            ->update(['request_date' => now()->subDays(2)]);

        $this->postJson('/api/account/email/confirm', ['token' => $token])->assertStatus(422);

        $this->assertSame(self::OLD_EMAIL, $this->storedEmail($account->refresh()));
    }

    #[Test]
    public function a_new_request_supersedes_the_previous_one(): void
    {
        $account = $this->signedIn();

        $first = $this->requestChange('first@example.com');
        $second = $this->requestChange('second@example.com');

        $this->assertNotSame($first, $second);

        $this->postJson('/api/account/email/confirm', ['token' => $first])->assertStatus(422);
        $this->postJson('/api/account/email/confirm', ['token' => $second])->assertOk();

        $this->assertSame('second@example.com', $this->storedEmail($account->refresh()));
    }

    #[Test]
    public function another_accounts_token_cannot_be_used(): void
    {
        $this->signedIn();
        $token = $this->requestChange();

        $this->postJson('/api/auth/logout')->assertOk();

        $other = Account::factory()
            ->named('other')
            ->withPassword(self::PASSWORD)
            ->state(['email' => 'other@example.com'])
            ->create();

        $this->postJson('/api/auth/login', [
            'username' => 'other',
            'password' => self::PASSWORD,
        ])->assertOk();

        /*
         * The confirmation is keyed on the signed-in account as well as the
         * token, so a token read out of somebody else's mailbox is not enough.
         */
        $this->postJson('/api/account/email/confirm', ['token' => $token])->assertStatus(422);

        $this->assertSame('other@example.com', $this->storedEmail($other->refresh()));
    }

    #[Test]
    public function an_address_taken_since_the_request_is_refused_at_confirmation(): void
    {
        $account = $this->signedIn();
        $token = $this->requestChange();

        Account::factory()->named('squatter')->state(['email' => self::NEW_EMAIL])->create();

        $this->postJson('/api/account/email/confirm', ['token' => $token])->assertStatus(422);

        $this->assertSame(self::OLD_EMAIL, $this->storedEmail($account->refresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | Without confirmation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_confirmation_off_the_address_moves_immediately(): void
    {
        config()->set('panel.email_change.require_confirmation', false);

        $account = $this->signedIn();

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', false)
            ->assertJsonPath('email', self::NEW_EMAIL);

        $this->assertSame(self::NEW_EMAIL, $this->storedEmail($account->refresh()));

        Mail::assertNotSent(EmailChangeConfirmationMail::class);

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_emailchange')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertSame(1, (int) $row->change_done, 'An immediate change still leaves a record.');
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_cannot_change_an_address(): void
    {
        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])->assertStatus(401);
    }

    #[Test]
    public function the_current_password_is_required(): void
    {
        /*
         * The legacy panel asked for nothing here, so a stolen session cookie
         * was enough to move the address -- and with it, the account.
         */
        $account = $this->signedIn();

        $this->putJson('/api/account/email', [
            'current_password' => 'NotMyPassword1!',
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame(self::OLD_EMAIL, $this->storedEmail($account->refresh()));
        Mail::assertNotSent(EmailChangeConfirmationMail::class);
    }

    #[Test]
    public function the_current_address_is_refused(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::OLD_EMAIL,
            'email_confirmation' => self::OLD_EMAIL,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function an_address_another_account_holds_is_refused(): void
    {
        $this->signedIn();

        Account::factory()->named('squatter')->state(['email' => self::NEW_EMAIL])->create();

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function mismatched_addresses_are_refused(): void
    {
        $this->signedIn();

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => 'typo@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function an_address_longer_than_the_column_is_refused(): void
    {
        $this->signedIn();

        // rAthena's login.email is varchar(39).
        $long = str_repeat('a', 32).'@example.com';

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => $long,
            'email_confirmation' => $long,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function requests_are_throttled(): void
    {
        $this->signedIn();

        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/api/account/email', [
                'current_password' => 'Wrong'.$i.'Pass!',
                'email' => self::NEW_EMAIL,
                'email_confirmation' => self::NEW_EMAIL,
            ])->assertStatus(422);
        }

        $this->putJson('/api/account/email', [
            'current_password' => self::PASSWORD,
            'email' => self::NEW_EMAIL,
            'email_confirmation' => self::NEW_EMAIL,
        ])->assertStatus(429);
    }
}
