<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Donations.
 *
 * Ports the coverage for donate/index, notify, complete, history, trusted and
 * update.
 *
 * Crediting an account cannot be undone in practice — the player spends the
 * credits and the items are delivered in game — so these tests are mostly
 * about what is refused.
 */
final class DonationTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('panel.donations.enabled', true);
        config()->set('panel.donations.receiver_emails', ['payments@example.test']);
        config()->set('panel.donations.currency', 'USD');
        config()->set('panel.donations.credits_per_unit', 10.0);
        config()->set('panel.donations.hold_hours', 0);
    }

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function balanceOf(Account $account): int
    {
        return (int) (DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)->value('balance') ?? 0);
    }

    /** Answers the verification call-back with the given word. */
    private function verifierSays(string $answer): void
    {
        Http::fake(['*' => Http::response($answer, 200)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(Account $account, array $overrides = []): array
    {
        return [
            'txn_id' => 'TXN'.$account->account_id.'A',
            'txn_type' => 'web_accept',
            'payment_status' => 'Completed',
            'receiver_email' => 'payments@example.test',
            'payer_email' => 'player@example.test',
            'mc_gross' => '10.00',
            'mc_currency' => 'USD',
            'custom' => (string) $account->account_id,
            ...$overrides,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Crediting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_verified_completed_payment_adds_credits(): void
    {
        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        $this->assertSame(100, $this->balanceOf($account), '10.00 at 10 credits per unit.');
        $this->assertDatabaseHas('cp_txnlog', [
            'account_id' => $account->account_id, 'credits' => 100,
        ], $this->loginConnection());
    }

    #[Test]
    public function an_unverified_notification_credits_nothing(): void
    {
        /*
         * The endpoint is a public URL that anybody can POST to. The only
         * thing that makes a notification trustworthy is the provider
         * confirming it sent it.
         */
        $this->verifierSays('INVALID');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        $this->assertSame(0, $this->balanceOf($account));
        $this->assertDatabaseCount('cp_txnlog', 0, $this->loginConnection());
    }

    #[Test]
    public function an_unreachable_verifier_credits_nothing(): void
    {
        // Fail closed: treating "unknown" as "genuine" turns an outage at the
        // provider into free credits.
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        $this->assertSame(0, $this->balanceOf($account));
    }

    #[Test]
    public function a_resent_notification_does_not_credit_twice(): void
    {
        /*
         * Providers resend by design until acknowledged. Without an
         * idempotency check, a resend is free credits — and this is the one
         * that is easiest to leave out.
         */
        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();
        $payment = $this->payment($account);

        $this->post('/api/donate/notify', $payment)->assertOk();
        $this->post('/api/donate/notify', $payment)->assertOk();
        $this->post('/api/donate/notify', $payment)->assertOk();

        $this->assertSame(100, $this->balanceOf($account));
        $this->assertSame(1, DB::connection($this->loginConnection())->table('cp_txnlog')->count());
    }

    #[Test]
    public function a_payment_to_another_address_is_refused(): void
    {
        // Otherwise a payment made to somebody else's account credits a player
        // here.
        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account, [
            'receiver_email' => 'someone-else@evil.test',
        ]))->assertOk();

        $this->assertSame(0, $this->balanceOf($account));

        // Recorded without crediting, so an administrator can see it arrived.
        $this->assertDatabaseHas('cp_txnlog', [
            'account_id' => $account->account_id, 'credits' => 0,
        ], $this->loginConnection());
    }

    #[Test]
    public function a_payment_in_another_currency_is_refused(): void
    {
        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account, ['mc_currency' => 'EUR']))
            ->assertOk();

        $this->assertSame(0, $this->balanceOf($account));
    }

    #[Test]
    public function a_payment_that_has_not_completed_is_refused(): void
    {
        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        foreach (['Pending', 'Refunded', 'Reversed'] as $status) {
            $this->post('/api/donate/notify', $this->payment($account, [
                'txn_id' => 'TXN-'.$status,
                'payment_status' => $status,
            ]))->assertOk();
        }

        $this->assertSame(0, $this->balanceOf($account));
    }

    #[Test]
    public function a_payment_naming_no_account_is_recorded_but_not_credited(): void
    {
        // Money somebody sent that could not be matched. An administrator
        // needs to see it to sort it out by hand.
        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account, ['custom' => '999999']))
            ->assertOk();

        $this->assertDatabaseHas('cp_txnlog', ['credits' => 0], $this->loginConnection());
        $this->assertSame(0, $this->balanceOf($account));
    }

    #[Test]
    public function nothing_is_credited_when_donations_are_off(): void
    {
        config()->set('panel.donations.enabled', false);

        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        $this->assertSame(0, $this->balanceOf($account));
    }

    #[Test]
    public function the_notification_endpoint_always_answers_ok(): void
    {
        // A non-2xx makes the provider retry, and a notification refused on
        // purpose should not be retried forever.
        $this->verifierSays('INVALID');

        $this->post('/api/donate/notify', [])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The hold queue
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_first_payment_is_held_rather_than_credited(): void
    {
        config()->set('panel.donations.hold_hours', 72);

        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        $this->assertSame(0, $this->balanceOf($account), 'Held, not credited.');

        $row = DB::connection($this->loginConnection())->table('cp_txnlog')->first();

        $this->assertNotNull($row->hold_until);
        $this->assertSame(100, (int) $row->credits, 'The amount is recorded for later.');
    }

    #[Test]
    public function a_held_payment_is_released_once_its_window_passes(): void
    {
        config()->set('panel.donations.hold_hours', 72);

        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        DB::connection($this->loginConnection())->table('cp_txnlog')
            ->update(['hold_until' => now()->subHour()]);

        $this->artisan('panel:release-held-credits')->assertSuccessful();

        $this->assertSame(100, $this->balanceOf($account));

        // And the payer is trusted from now on.
        $this->assertDatabaseHas('cp_trusted', [
            'account_id' => $account->account_id, 'email' => 'player@example.test',
        ], $this->loginConnection());
    }

    #[Test]
    public function a_payment_reversed_inside_its_window_is_cancelled(): void
    {
        /*
         * The case the hold exists for. Without it, a chargeback leaves the
         * server having delivered items for money it no longer has.
         */
        config()->set('panel.donations.hold_hours', 72);

        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();
        $payment = $this->payment($account);

        $this->post('/api/donate/notify', $payment)->assertOk();

        // The reversal arrives as its own notification referencing the first.
        $this->post('/api/donate/notify', $this->payment($account, [
            'txn_id' => 'TXN-REVERSAL',
            'parent_txn_id' => $payment['txn_id'],
            'payment_status' => 'Reversed',
        ]))->assertOk();

        DB::connection($this->loginConnection())->table('cp_txnlog')
            ->where('txn_id', $payment['txn_id'])
            ->update(['hold_until' => now()->subHour()]);

        $this->artisan('panel:release-held-credits')->assertSuccessful();

        $this->assertSame(0, $this->balanceOf($account), 'Nothing was ever spendable.');

        $this->assertSame(
            0,
            (int) DB::connection($this->loginConnection())->table('cp_txnlog')
                ->where('txn_id', $payment['txn_id'])->value('credits'),
        );
    }

    #[Test]
    public function a_trusted_payer_is_credited_immediately(): void
    {
        config()->set('panel.donations.hold_hours', 72);

        $this->verifierSays('VERIFIED');

        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_trusted')->insert([
            'account_id' => $account->account_id,
            'email' => 'player@example.test',
            'create_date' => now(),
        ]);

        $this->post('/api/donate/notify', $this->payment($account))->assertOk();

        $this->assertSame(100, $this->balanceOf($account));
    }

    /*
    |--------------------------------------------------------------------------
    | The player's pages
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_donate_page_publishes_only_public_configuration(): void
    {
        config()->set('panel.donations.business_email', 'payments@example.test');

        $response = $this->actingAs(Account::factory()->create())
            ->getJson('/api/donate')
            ->assertOk();

        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.currency', 'USD')
            // JSON has one number type, so 10.0 arrives as 10.
            ->assertJsonPath('data.credits_per_unit', 10);

        // The verification endpoint is server-side only.
        $this->assertStringNotContainsString('ipnpb', $response->getContent());
    }

    #[Test]
    public function the_donate_page_says_so_when_donations_are_off(): void
    {
        config()->set('panel.donations.enabled', false);

        $this->getJson('/api/donate')->assertOk()->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function the_return_url_does_not_credit_anything(): void
    {
        /*
         * A return URL is a redirect in the payer's browser and proves
         * nothing. Anybody can visit it.
         */
        $account = Account::factory()->create();

        $this->actingAs($account)->getJson('/api/donate/complete')->assertOk();

        $this->assertSame(0, $this->balanceOf($account));
        $this->assertDatabaseCount('cp_txnlog', 0, $this->loginConnection());
    }

    #[Test]
    public function the_history_shows_only_the_accounts_own_donations(): void
    {
        $this->verifierSays('VERIFIED');

        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();

        $this->post('/api/donate/notify', $this->payment($mine))->assertOk();
        $this->post('/api/donate/notify', $this->payment($theirs))->assertOk();

        $this->actingAs($mine)
            ->getJson('/api/donate/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.credits', 100);
    }

    #[Test]
    public function the_trusted_list_is_the_accounts_own(): void
    {
        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_trusted')->insert([
            ['account_id' => $mine->account_id, 'email' => 'mine@example.test', 'create_date' => now()],
            ['account_id' => $theirs->account_id, 'email' => 'theirs@example.test', 'create_date' => now()],
        ]);

        $this->actingAs($mine)
            ->getJson('/api/donate/trusted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'mine@example.test');
    }

    /*
    |--------------------------------------------------------------------------
    | Adjusting a balance
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_administrator_can_set_a_balance_and_it_is_recorded(): void
    {
        /*
         * The legacy had this on the account edit form. A balance that changes
         * with no record is the thing an operator cannot answer a question
         * about later.
         */
        $player = Account::factory()->named('player')->create();
        $admin = Account::factory()->administrator()->named('boss')->create();

        $this->actingAs($admin)
            ->putJson("/api/admin/accounts/{$player->account_id}", ['balance' => 250])
            ->assertOk();

        $this->assertSame(250, $this->balanceOf($player));

        $row = DB::connection($this->loginConnection())->table('cp_txnlog')
            ->where('account_id', $player->account_id)->first();

        $this->assertSame(250, (int) $row->credits);
        $this->assertStringContainsString('boss', (string) $row->item_name);
    }

    #[Test]
    public function setting_a_balance_needs_its_own_ability(): void
    {
        Gate::define('EditAccountBalance', static fn (): bool => false);

        $player = Account::factory()->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson("/api/admin/accounts/{$player->account_id}", ['balance' => 250])
            ->assertStatus(403);

        $this->assertSame(0, $this->balanceOf($player));
    }
}
