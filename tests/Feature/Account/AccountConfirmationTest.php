<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Mail\AccountConfirmationMail;
use App\Models\Account;
use App\Services\Rathena\AccountConfirmationService;
use App\Support\Rathena\ServerRegistry;
use App\Support\Tokens\SecureToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Following a confirmation link, and asking for another one.
 *
 * Ports the coverage for modules/account/confirm.php and resend.php.
 */
final class AccountConfirmationTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Faked once. Mail::fake() replaces the container's mailer, but a
         * request that has already run leaves the previous instance resolved,
         * so re-faking mid-test sends messages to the old fake and makes the
         * new one look empty.
         */
        Mail::fake();

        config()->set('panel.registration.require_email_confirmation', true);
        config()->set('panel.registration.email_confirmation_expires_after_hours', 48);
        config()->set('panel.captcha.on_registration', false);
    }

    /**
     * Register an account that is awaiting confirmation, and return its token.
     *
     * @return array{0: Account, 1: string}
     */
    private function heldAccount(string $username = 'awaiting'): array
    {
        $account = Account::factory()
            ->named($username)
            ->withPassword(self::PASSWORD)
            ->state(['email' => $username.'@example.com'])
            ->create();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->insert([
                'account_id' => $account->account_id,
                'userid' => $account->userid,
                'user_pass' => '',
                'sex' => 'M',
                'email' => $account->email,
                'reg_date' => now(),
                'reg_ip' => '127.0.0.1',
                'confirmed' => 1,
            ]);

        $servers = $this->app->make(ServerRegistry::class);
        $token = $this->app->make(AccountConfirmationService::class)
            ->issue($servers->current(), $account);

        return [$account->refresh(), $token->plaintext];
    }

    private function tokenFromLatestConfirmationMail(): string
    {
        $sent = Mail::sent(AccountConfirmationMail::class);

        $this->assertNotEmpty($sent, 'No confirmation mail was sent.');

        preg_match('/token=([0-9a-f]{64})/', $sent->last()->render(), $matches);

        $this->assertArrayHasKey(1, $matches, 'No confirmation token was found in the sent mail.');

        return $matches[1];
    }

    /*
    |--------------------------------------------------------------------------
    | Confirming
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_valid_token_activates_the_account(): void
    {
        [$account, $token] = $this->heldAccount();

        $this->assertSame(5, $account->state);

        $this->postJson('/api/auth/confirm', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('message', trans('accounts.confirmation.confirmed'));

        $this->assertSame(0, $account->refresh()->state);

        $this->postJson('/api/auth/login', [
            'username' => 'awaiting',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function confirming_marks_the_registration_and_clears_the_token(): void
    {
        [$account, $token] = $this->heldAccount();

        $this->postJson('/api/auth/confirm', ['token' => $token])->assertOk();

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertSame(1, (int) $row->confirmed);
        // Cleared, so a used link is no longer matchable even as a digest.
        $this->assertNull($row->confirm_code);
        $this->assertNull($row->confirm_expire);
    }

    #[Test]
    public function the_lift_is_recorded_in_the_ban_history(): void
    {
        [$account, $token] = $this->heldAccount();

        $this->postJson('/api/auth/confirm', ['token' => $token])->assertOk();

        /*
         * cp_banlog is append-only: the hold and the lift are two rows, not an
         * edit of one. An operator reading the history should see both.
         */
        $rows = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_banlog')
            ->where('account_id', $account->account_id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[0]->ban_type, 'The hold is a permanent ban row.');
        $this->assertSame(0, (int) $rows[1]->ban_type, 'The lift is a separate row.');
    }

    #[Test]
    public function a_token_can_only_be_used_once(): void
    {
        [, $token] = $this->heldAccount();

        $this->postJson('/api/auth/confirm', ['token' => $token])->assertOk();

        $this->postJson('/api/auth/confirm', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('accounts.confirmation.invalid'));
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        [$account, $token] = $this->heldAccount();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->update(['confirm_expire' => now()->subHour()]);

        $this->postJson('/api/auth/confirm', ['token' => $token])->assertStatus(422);

        $this->assertSame(5, $account->refresh()->state, 'An expired link must leave the account held.');
    }

    #[Test]
    public function an_expiry_is_always_written(): void
    {
        /*
         * The legacy panel wrote confirm_expire only when EmailConfirmExpire
         * was configured, while confirm.php always required
         * `confirm_expire > NOW()`. A NULL comparison is never true in SQL, so
         * with that setting at its default no account could ever be confirmed.
         */
        config()->set('panel.registration.email_confirmation_expires_after_hours', 0);

        [$account, $token] = $this->heldAccount();

        $expiry = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->value('confirm_expire');

        $this->assertNotNull($expiry, 'An expiry must be written even when the setting is zero.');

        $this->postJson('/api/auth/confirm', ['token' => $token])->assertOk();
    }

    #[Test]
    public function an_unknown_or_malformed_token_is_refused_the_same_way(): void
    {
        $this->heldAccount();

        $unknown = SecureToken::generate()->plaintext;

        $this->postJson('/api/auth/confirm', ['token' => $unknown])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('accounts.confirmation.invalid'));

        // A malformed one is refused by the rules, before it reaches a query.
        $this->postJson('/api/auth/confirm', ['token' => 'not-a-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    #[Test]
    public function the_stored_digest_is_not_accepted_as_a_token(): void
    {
        [$account] = $this->heldAccount();

        $digest = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->value('confirm_code');

        /*
         * The point of storing a digest. Somebody who can read the table must
         * not be able to activate accounts with what they find in it.
         */
        $this->postJson('/api/auth/confirm', ['token' => $digest])->assertStatus(422);

        $this->assertSame(5, $account->refresh()->state);
    }

    /*
    |--------------------------------------------------------------------------
    | Resending
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resending_issues_a_new_token_and_invalidates_the_old_one(): void
    {
        [, $original] = $this->heldAccount();

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'awaiting',
            'email' => 'awaiting@example.com',
        ])->assertOk();

        $reissued = $this->tokenFromLatestConfirmationMail();

        $this->assertNotSame($original, $reissued);

        // The superseded link stops working, so only one is ever live.
        $this->postJson('/api/auth/confirm', ['token' => $original])->assertStatus(422);
        $this->postJson('/api/auth/confirm', ['token' => $reissued])->assertOk();
    }

    #[Test]
    public function an_expired_request_can_still_be_renewed(): void
    {
        /*
         * The legacy resend required `confirm_expire > NOW()`, which refused
         * the one case where somebody actually needs a new link: the old one
         * has lapsed.
         */
        [$account] = $this->heldAccount();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->update(['confirm_expire' => now()->subDays(5)]);

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'awaiting',
            'email' => 'awaiting@example.com',
        ])->assertOk();

        Mail::assertSent(AccountConfirmationMail::class);
    }

    #[Test]
    public function resending_answers_identically_for_an_account_that_does_not_exist(): void
    {
        $expected = trans('accounts.confirmation.resent');

        $this->heldAccount();

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'awaiting',
            'email' => 'awaiting@example.com',
        ])->assertOk()->assertJsonPath('message', $expected);

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'nobody',
            'email' => 'nobody@example.com',
        ])->assertOk()->assertJsonPath('message', $expected);
    }

    #[Test]
    public function resending_requires_the_address_on_the_account(): void
    {
        $this->heldAccount();

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'awaiting',
            'email' => 'attacker@example.com',
        ])->assertOk();

        /*
         * Nothing sent. Without the address check this endpoint would mail a
         * working activation link to any address, for any account name.
         */
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_confirmed_account_cannot_have_confirmation_resent(): void
    {
        [, $token] = $this->heldAccount();

        $this->postJson('/api/auth/confirm', ['token' => $token])->assertOk();

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'awaiting',
            'email' => 'awaiting@example.com',
        ])->assertOk();

        Mail::assertNothingSent();
    }

    #[Test]
    public function resending_is_throttled_per_account(): void
    {
        $this->heldAccount();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/confirm/resend', [
                'username' => 'awaiting',
                'email' => 'awaiting@example.com',
            ])->assertOk();
        }

        $this->postJson('/api/auth/confirm/resend', [
            'username' => 'awaiting',
            'email' => 'awaiting@example.com',
        ])->assertStatus(429);
    }
}
