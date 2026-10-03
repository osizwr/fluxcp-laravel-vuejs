<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\Account;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Deleting registrations that were never confirmed.
 *
 * This command deletes accounts, so the tests are mostly about what it leaves
 * alone. The legacy query was keyed on the audit row's `confirmed` flag and
 * nothing else, which meant anything that set that flag to 0 on a live account
 * -- a restored backup, a hand-written UPDATE, a broken admin tool -- handed
 * that account to it.
 */
final class PruneUnconfirmedTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('panel.maintenance.prune_unconfirmed_accounts', true);
    }

    /**
     * @param  array<string, mixed>  $logOverrides
     */
    private function account(string $username, array $state = [], array $logOverrides = []): Account
    {
        $account = Account::factory()->named($username)->state($state)->create();

        DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->insert([
                'account_id' => $account->account_id,
                'userid' => $account->userid,
                'user_pass' => '',
                'sex' => 'M',
                'email' => $account->email,
                'reg_date' => now()->subDays(7),
                'reg_ip' => '127.0.0.1',
                'confirmed' => 0,
                'confirm_code' => str_repeat('a', 32),
                'confirm_expire' => now()->subDays(3),
                ...$logOverrides,
            ]);

        return $account;
    }

    private function accountExists(Account $account): bool
    {
        return DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | What it removes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_lapsed_unconfirmed_registration_is_deleted(): void
    {
        $account = $this->account('lapsed', ['state' => 5]);

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertFalse($this->accountExists($account));

        // The audit row goes too, or the next run keeps finding it.
        $this->assertDatabaseMissing(
            'cp_createlog',
            ['account_id' => $account->account_id],
            $this->serverGroup()->loginConnection(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | What it leaves alone
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_confirmed_account_is_kept(): void
    {
        $account = $this->account('confirmed', ['state' => 0], ['confirmed' => 1]);

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function a_registration_still_inside_its_window_is_kept(): void
    {
        $account = $this->account('waiting', ['state' => 5], [
            'confirm_expire' => now()->addDay(),
        ]);

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function an_account_no_longer_held_is_kept(): void
    {
        /*
         * state 0 with confirmed = 0 is an inconsistency, not a candidate.
         * Deleting on the audit row alone is what makes a stale flag
         * destructive.
         */
        $account = $this->account('released', ['state' => 0]);

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function an_account_with_characters_is_kept_and_reported(): void
    {
        $account = $this->account('hascharacters', ['state' => 5]);

        Character::factory()->forAccount($account)->named('Somebody')->create();

        $this->artisan('panel:prune-unconfirmed')
            ->expectsOutputToContain('owns characters')
            ->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function a_staff_account_is_kept(): void
    {
        $account = $this->account('gamemaster', ['state' => 5, 'group_id' => 99]);

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function an_account_with_no_registration_record_is_kept(): void
    {
        /*
         * Accounts created in game, or by rAthena's own tools, have no
         * cp_createlog row at all. They must never be candidates.
         */
        $account = Account::factory()->named('fromgame')->state(['state' => 5])->create();

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function a_row_with_no_confirmation_code_is_kept(): void
    {
        $account = $this->account('nocode', ['state' => 5], ['confirm_code' => null]);

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function nothing_is_deleted_while_pruning_is_off(): void
    {
        config()->set('panel.maintenance.prune_unconfirmed_accounts', false);

        $account = $this->account('lapsed', ['state' => 5]);

        $this->artisan('panel:prune-unconfirmed')
            ->expectsOutputToContain('Pruning is off')
            ->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $account = $this->account('lapsed', ['state' => 5]);

        $this->artisan('panel:prune-unconfirmed --dry-run')
            ->expectsOutputToContain('would delete')
            ->assertSuccessful();

        $this->assertTrue($this->accountExists($account));
    }

    #[Test]
    public function a_dry_run_works_even_when_pruning_is_off(): void
    {
        // So an operator can see what it would do before enabling it.
        config()->set('panel.maintenance.prune_unconfirmed_accounts', false);

        $this->account('lapsed', ['state' => 5]);

        $this->artisan('panel:prune-unconfirmed --dry-run')
            ->expectsOutputToContain('would delete')
            ->assertSuccessful();
    }

    #[Test]
    public function unrelated_accounts_are_untouched(): void
    {
        $doomed = $this->account('lapsed', ['state' => 5]);
        $bystander = Account::factory()->named('bystander')->create();

        $this->artisan('panel:prune-unconfirmed')->assertSuccessful();

        $this->assertFalse($this->accountExists($doomed));
        $this->assertTrue($this->accountExists($bystander));
    }
}
