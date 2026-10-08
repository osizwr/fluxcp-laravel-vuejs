<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Mail\AccountConfirmationMail;
use App\Models\Account;
use App\Models\PanelCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The registration endpoint.
 *
 * Ports the coverage for modules/account/create.php.
 */
final class RegistrationTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config()->set('panel.registration.enabled', true);
        config()->set('panel.registration.require_email_confirmation', false);
        config()->set('panel.captcha.on_registration', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'username' => 'newplayer',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'email' => 'newplayer@example.com',
            'gender' => 'M',
            ...$overrides,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The happy path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function registering_creates_an_account_and_signs_it_in(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.username', 'newplayer')
            ->assertJsonPath('data.email', 'newplayer@example.com');

        $this->assertAuthenticated();

        $account = Account::query()->where('userid', 'newplayer')->first();

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame(0, $account->state, 'A registration needing no confirmation must be usable.');
    }

    #[Test]
    public function the_password_is_stored_in_both_formats_and_never_as_a_laravel_hash(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $stored = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('userid', 'newplayer')
            ->value('user_pass');

        /*
         * The whole point of D1. rAthena's login server reads this column
         * directly, so a bcrypt hash here is an account that can never log in
         * to the game.
         */
        $this->assertSame(self::PASSWORD, $stored);
        $this->assertStringStartsNotWith('$2y$', $stored);

        $credential = PanelCredential::query()
            ->where('account_id', Account::query()->where('userid', 'newplayer')->value('account_id'))
            ->first();

        $this->assertNotNull($credential, 'The panel keeps its own hash of the password.');
        $this->assertTrue(Hash::check(self::PASSWORD, $credential->password_hash));
    }

    #[Test]
    public function the_registration_is_recorded_without_the_password(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('userid', 'newplayer')
            ->first();

        $this->assertNotNull($row);
        // The legacy panel wrote the password here. See D2.
        $this->assertSame('', $row->user_pass);
        $this->assertSame(1, (int) $row->confirmed);
    }

    #[Test]
    public function the_new_account_can_immediately_sign_in(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/login', [
            'username' => 'newplayer',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Confirmation required
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_confirmation_required_the_account_is_held_and_not_signed_in(): void
    {
        config()->set('panel.registration.require_email_confirmation', true);

        $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('confirmation_sent', true);

        // Registering an account that needs confirming must not sign it in.
        $this->assertGuest();

        $account = Account::query()->where('userid', 'newplayer')->first();

        $this->assertSame(5, $account->state, 'An unconfirmed account sits in rAthena state 5.');
        $this->assertSame(0, $account->unban_time);
    }

    #[Test]
    public function a_held_account_cannot_sign_in_and_is_told_why(): void
    {
        config()->set('panel.registration.require_email_confirmation', true);

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/auth/login', [
            'username' => 'newplayer',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            /*
             * Specifically "needs confirming", not "banned". Both states are 5
             * in rAthena, and telling a new registrant they are banned is the
             * failure this distinction exists to avoid.
             */
            ->assertJsonPath('errors.username.0', trans('auth.failure.pending_confirmation'));
    }

    #[Test]
    public function the_confirmation_mail_carries_a_link_and_no_password(): void
    {
        config()->set('panel.registration.require_email_confirmation', true);

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        Mail::assertQueuedCount(0);
        Mail::assertSent(AccountConfirmationMail::class, function (AccountConfirmationMail $mail): bool {
            $mail->assertTo('newplayer@example.com');

            $rendered = $mail->render();

            $this->assertStringContainsString('/confirm-account?token=', $rendered);
            // Never. Not in this mail, not in any of them.
            $this->assertStringNotContainsString(self::PASSWORD, $rendered);

            return true;
        });
    }

    #[Test]
    public function the_confirmation_token_is_not_stored_in_the_database(): void
    {
        config()->set('panel.registration.require_email_confirmation', true);

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $token = $this->tokenFromSentMail();

        $stored = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('userid', 'newplayer')
            ->value('confirm_code');

        $this->assertNotSame($token, $stored, 'The database must hold a digest, not the token.');
        $this->assertSame(32, strlen($stored));
    }

    #[Test]
    public function the_held_ban_record_does_not_contain_the_token(): void
    {
        config()->set('panel.registration.require_email_confirmation', true);

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $token = $this->tokenFromSentMail();

        $reason = (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_banlog')
            ->orderByDesc('id')
            ->value('ban_reason');

        /*
         * The legacy panel put the confirmation code in the ban reason, which
         * copied a working activation link into a table the admin screens show.
         */
        $this->assertStringNotContainsString($token, $reason);
        $this->assertNotSame('', $reason, 'A held account still needs a recorded reason.');
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function registration_can_be_closed(): void
    {
        config()->set('panel.registration.enabled', false);

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('message', trans('accounts.registration.disabled'));

        $this->assertDatabaseCount('login', 0, $this->serverGroup()->loginConnection());
    }

    #[Test]
    public function a_signed_in_visitor_cannot_register(): void
    {
        $this->actingAs(Account::factory()->create());

        $this->postJson('/api/auth/register', $this->payload())->assertStatus(403);
    }

    #[Test]
    public function mismatched_passwords_are_refused(): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            'password_confirmation' => 'SomethingElse1!',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function a_taken_account_name_is_refused(): void
    {
        Account::factory()->named('newplayer')->create();

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    #[Test]
    public function a_taken_email_is_refused_when_duplicates_are_off(): void
    {
        config()->set('panel.registration.allow_duplicate_emails', false);

        Account::factory()->state(['email' => 'newplayer@example.com'])->create();

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function a_weak_password_is_refused_by_the_service_policy(): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function a_password_longer_than_the_column_is_refused(): void
    {
        /*
         * Not a policy preference. rAthena's user_pass is varchar(32), so a
         * longer cleartext password is truncated on write and could then never
         * be matched -- an account that cannot sign in anywhere.
         */
        $password = str_repeat('Aa1!', 12);

        $this->postJson('/api/auth/register', $this->payload([
            'password' => $password,
            'password_confirmation' => $password,
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * The form no longer asks, so the endpoint must not insist.
     *
     * The default lives in the request rather than in the client, so this is
     * where it is proved: a payload with no gender at all has to produce a
     * usable account rather than a validation error, whichever client sent it.
     */
    #[Test]
    public function an_omitted_gender_defaults_to_male(): void
    {
        $payload = $this->payload();
        unset($payload['gender']);

        $this->postJson('/api/auth/register', $payload)->assertCreated();

        $this->assertSame(
            'M',
            DB::connection($this->serverGroup()->loginConnection())
                ->table('login')
                ->where('userid', 'newplayer')
                ->value('sex'),
        );
    }

    #[Test]
    public function a_server_gender_cannot_be_chosen(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['gender' => 'S']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gender');
    }

    /**
     * The form no longer asks for a date of birth, and a client that sends
     * one anyway does not get it stored: rAthena's column stays empty for
     * accounts made here, rather than carrying a number nobody checked.
     */
    #[Test]
    public function no_birthdate_is_asked_for_or_stored(): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            'birthdate' => '1995-04-12',
        ]))->assertCreated();

        $account = Account::query()->where('userid', 'newplayer')->first();

        $this->assertInstanceOf(Account::class, $account);
        $this->assertNull($account->birthdate);
    }

    #[Test]
    public function bulk_registration_from_one_address_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', $this->payload([
                'username' => 'bulk'.$i,
                'email' => "bulk{$i}@example.com",
            ]));

            // Each success signs the visitor in, and registration is
            // guests-only, so the session has to be dropped between attempts.
            $this->postJson('/api/auth/logout');
        }

        $this->postJson('/api/auth/register', $this->payload([
            'username' => 'onemore',
            'email' => 'onemore@example.com',
        ]))->assertStatus(429);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function tokenFromSentMail(): string
    {
        $token = null;

        Mail::assertSent(AccountConfirmationMail::class, function (AccountConfirmationMail $mail) use (&$token): bool {
            preg_match('/token=([0-9a-f]{64})/', $mail->render(), $matches);
            $token = $matches[1] ?? null;

            return true;
        });

        $this->assertIsString($token, 'No confirmation token was found in the sent mail.');

        return $token;
    }
}
