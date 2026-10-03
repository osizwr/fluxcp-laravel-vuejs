<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The sign-in, sign-out and current-account endpoints.
 */
final class AuthEndpointTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login:merchant|127.0.0.1');
        RateLimiter::clear('login-address:127.0.0.1');
    }

    #[Test]
    public function it_signs_an_account_in_and_returns_it(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $response = $this->postJson('/api/auth/login', [
            'username' => 'merchant',
            'password' => 'Zeny4Days!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.username', 'merchant')
            ->assertJsonPath('data.group.is_staff', false);

        $this->assertAuthenticated();
    }

    #[Test]
    public function it_never_returns_the_password_column(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $response = $this->postJson('/api/auth/login', [
            'username' => 'merchant',
            'password' => 'Zeny4Days!',
        ]);

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('Zeny4Days!', $body);
        $this->assertStringNotContainsString('user_pass', $body);
    }

    #[Test]
    public function it_rejects_bad_credentials_with_a_validation_error(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/auth/login', [
            'username' => 'merchant',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $this->assertGuest();
    }

    #[Test]
    public function it_does_not_reveal_whether_an_account_exists(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $wrongPassword = $this->postJson('/api/auth/login', [
            'username' => 'merchant', 'password' => 'wrong',
        ]);

        $noSuchAccount = $this->postJson('/api/auth/login', [
            'username' => 'nobody-at-all', 'password' => 'wrong',
        ]);

        $this->assertSame(
            $wrongPassword->json('errors.username'),
            $noSuchAccount->json('errors.username'),
            'A wrong password and an unknown account must be indistinguishable.',
        );
    }

    #[Test]
    public function it_throttles_repeated_failures(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $attempts = (int) config('panel.login.max_attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'merchant', 'password' => 'wrong',
            ])->assertStatus(422);
        }

        // The legacy panel had no rate limiting, which with cleartext
        // credentials made online guessing cheap.
        $this->postJson('/api/auth/login', [
            'username' => 'merchant', 'password' => 'Zeny4Days!',
        ])->assertStatus(429);
    }

    #[Test]
    public function a_successful_sign_in_clears_the_throttle(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/auth/login', ['username' => 'merchant', 'password' => 'wrong'])
            ->assertStatus(422);

        $this->postJson('/api/auth/login', ['username' => 'merchant', 'password' => 'Zeny4Days!'])
            ->assertOk();

        $this->assertFalse(RateLimiter::tooManyAttempts('login:merchant|127.0.0.1', 1));
    }

    #[Test]
    public function it_records_the_attempt_without_the_password(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/auth/login', ['username' => 'merchant', 'password' => 'wrong']);
        $this->postJson('/api/auth/login', ['username' => 'merchant', 'password' => 'Zeny4Days!']);

        $rows = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_loginlog')
            ->orderBy('id')
            ->get();

        // Both attempts are audited, as FluxCP did, with the failure carrying
        // its error code.
        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[0]->error_code, 'Expected the InvalidCredentials code.');
        $this->assertNull($rows[1]->error_code, 'A success carries no error code.');

        foreach ($rows as $row) {
            $this->assertSame('', $row->password, 'No password may be recorded. See D2.');
        }
    }

    #[Test]
    public function a_signed_in_visitor_cannot_reach_the_sign_in_endpoint(): void
    {
        // The permission map holds this route at UNAUTH, which means guests
        // only rather than guests and above.
        $account = Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->actingAs($account)
            ->postJson('/api/auth/login', ['username' => 'merchant', 'password' => 'Zeny4Days!'])
            ->assertStatus(403);
    }

    #[Test]
    public function it_signs_an_account_out(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($account)->postJson('/api/auth/logout')->assertOk();

        $this->assertGuest();
    }

    #[Test]
    public function the_current_account_endpoint_requires_a_session(): void
    {
        $this->getJson('/api/account')->assertStatus(401);
    }

    #[Test]
    public function it_returns_the_signed_in_account(): void
    {
        $account = Account::factory()->named('merchant')->administrator()->create();

        $this->actingAs($account)
            ->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.username', 'merchant')
            ->assertJsonPath('data.group.is_staff', true)
            ->assertJsonPath('data.group.level', 99);
    }

    #[Test]
    public function it_reports_the_permissions_a_viewer_holds_when_asked(): void
    {
        // Sent so the client can hide controls. Every one is still enforced
        // server-side; hiding a control is never what stops an action.
        $account = Account::factory()->administrator()->create();

        $response = $this->actingAs($account)
            ->getJson('/api/account?with_permissions=1')
            ->assertOk();

        $permissions = $response->json('meta.permissions');

        $this->assertIsArray($permissions);
        $this->assertContains('ViewAccount', $permissions);
        // Pinned to Noone, so nobody holds it -- not even an administrator.
        $this->assertNotContains('SeeAccountPassword', $permissions);
    }
    /*
    |--------------------------------------------------------------------------
    | The credits field
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function credits_are_zero_for_an_account_with_no_credit_row(): void
    {
        /*
         * The common case: cp_credits gets a row on first donation, not with
         * the account. The client declares this field as a number and calls
         * .toLocaleString() on it, so a null is a TypeError on the account
         * page rather than a blank figure.
         *
         * Laravel's whenLoaded() looks like it covers this and does not — a
         * relation that is loaded but null returns null, not the default.
         */
        $account = Account::factory()->named('nocredits')->withPassword('Zeny4Days!')->create();

        $this->actingAs($account)
            ->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.credits', 0);
    }

    #[Test]
    public function credits_are_reported_when_the_account_has_a_balance(): void
    {
        $account = Account::factory()->named('hascredits')->withPassword('Zeny4Days!')->create();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_credits')
            ->insert(['account_id' => $account->account_id, 'balance' => 1234]);

        $this->actingAs($account)
            ->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.credits', 1234);
    }
}
