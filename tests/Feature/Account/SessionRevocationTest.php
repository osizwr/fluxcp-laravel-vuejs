<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\Account;
use App\Services\Auth\SessionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Ending other sessions for an account.
 *
 * Covered separately from the endpoint because it depends on the session
 * driver: the rest of the suite runs with the array driver, where there is no
 * store to query. These tests use the database driver, which is the default
 * for a real installation.
 */
final class SessionRevocationTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function registry(): SessionRegistry
    {
        return $this->app->make(SessionRegistry::class);
    }

    private function storedSession(string $id, ?int $accountId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $accountId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'tests',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    #[Test]
    public function every_session_but_the_current_one_is_ended(): void
    {
        config()->set('session.driver', 'database');

        $account = Account::factory()->create();

        $this->storedSession('current', $account->account_id);
        $this->storedSession('other-browser', $account->account_id);
        $this->storedSession('other-device', $account->account_id);

        $this->assertTrue($this->registry()->revokeOtherSessions($account, 'current'));

        $remaining = DB::table('sessions')->where('user_id', $account->account_id)->pluck('id')->all();

        $this->assertSame(['current'], $remaining);
    }

    #[Test]
    public function other_accounts_are_untouched(): void
    {
        config()->set('session.driver', 'database');

        $account = Account::factory()->create();
        $bystander = Account::factory()->create();

        $this->storedSession('mine', $account->account_id);
        $this->storedSession('theirs', $bystander->account_id);
        $this->storedSession('a-guest', null);

        $this->registry()->revokeOtherSessions($account, 'mine');

        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);
        $this->assertDatabaseHas('sessions', ['id' => 'a-guest']);
    }

    #[Test]
    public function passing_no_exception_ends_every_session(): void
    {
        /*
         * What a completed password reset does: whoever is signed in as this
         * account right now is the problem the reset is solving, so none of
         * those sessions is kept.
         */
        config()->set('session.driver', 'database');

        $account = Account::factory()->create();

        $this->storedSession('one', $account->account_id);
        $this->storedSession('two', $account->account_id);

        $this->assertTrue($this->registry()->revokeOtherSessions($account, null));

        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $account->account_id)->count(),
        );
    }

    #[Test]
    public function a_driver_with_no_queryable_store_reports_that_it_could_not(): void
    {
        /*
         * The important half. With the file or cookie driver these sessions
         * cannot be found, and saying otherwise would be a false reassurance
         * to somebody who has just changed their password because they think
         * it was stolen.
         */
        config()->set('session.driver', 'file');

        $account = Account::factory()->create();

        $this->assertFalse($this->registry()->revokeOtherSessions($account, 'current'));
    }
}
