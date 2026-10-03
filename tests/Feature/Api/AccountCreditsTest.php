<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Gender change, credit transfer, web commands and the custom siege schedule.
 *
 * Ports the coverage for account/changesex, transfer, xferlog,
 * webcommands/index and woe/custom.
 */
final class AccountCreditsTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    private function withCredits(Account $account, int $balance): void
    {
        DB::connection($this->loginConnection())->table('cp_credits')->updateOrInsert(
            ['account_id' => $account->account_id],
            ['balance' => $balance],
        );
    }

    private function balanceOf(Account $account): int
    {
        return (int) (DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)->value('balance') ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Gender change
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_free_gender_change_flips_the_account(): void
    {
        config()->set('panel.characters.gender_change_cost', 0);

        $account = Account::factory()->state(['sex' => 'M'])->create();

        $this->actingAs($account)
            ->postJson('/api/account/gender')
            ->assertOk()
            ->assertJsonPath('data.gender', 'F');

        $this->assertSame('F', $account->refresh()->sex->value);
    }

    #[Test]
    public function a_gender_linked_job_blocks_the_change(): void
    {
        /*
         * Bard and Dancer exist for one gender only. Changing the account
         * would leave the character as a class its new gender cannot be,
         * which the client renders as an invisible or crashing character.
         */
        $account = Account::factory()->state(['sex' => 'M'])->create();

        Character::factory()->forAccount($account)->named('Singer')
            ->state(['class' => 19])->create();

        $this->actingAs($account)
            ->postJson('/api/account/gender')
            ->assertStatus(422)
            ->assertJsonPath('blocking_characters.0', 'Singer');

        $this->assertSame('M', $account->refresh()->sex->value);
    }

    #[Test]
    public function a_paid_gender_change_deducts_the_cost(): void
    {
        config()->set('panel.characters.gender_change_cost', 50);

        $account = Account::factory()->state(['sex' => 'M'])->create();
        $this->withCredits($account, 120);

        $this->actingAs($account)->postJson('/api/account/gender')->assertOk();

        $this->assertSame(70, $this->balanceOf($account));
        $this->assertSame('F', $account->refresh()->sex->value);
    }

    #[Test]
    public function a_paid_gender_change_is_refused_without_the_credits(): void
    {
        config()->set('panel.characters.gender_change_cost', 50);

        $account = Account::factory()->state(['sex' => 'M'])->create();
        $this->withCredits($account, 10);

        $this->actingAs($account)->postJson('/api/account/gender')->assertStatus(422);

        $this->assertSame('M', $account->refresh()->sex->value);
        $this->assertSame(10, $this->balanceOf($account));
    }

    #[Test]
    public function staff_with_the_ability_change_gender_for_free(): void
    {
        config()->set('panel.characters.gender_change_cost', 50);

        $staff = Account::factory()->administrator()->state(['sex' => 'M'])->create();
        $this->withCredits($staff, 0);

        $this->actingAs($staff)->postJson('/api/account/gender')->assertOk();

        $this->assertSame('F', $staff->refresh()->sex->value);
        $this->assertSame(0, $this->balanceOf($staff));
    }

    /*
    |--------------------------------------------------------------------------
    | Credit transfer
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function credits_can_be_sent_to_another_player(): void
    {
        $sender = Account::factory()->create();
        $recipientAccount = Account::factory()->create();

        Character::factory()->forAccount($recipientAccount)->named('Friend')->create();

        $this->withCredits($sender, 100);

        $this->actingAs($sender)
            ->postJson('/api/account/credits/transfer', ['character' => 'Friend', 'credits' => 40])
            ->assertOk();

        $this->assertSame(60, $this->balanceOf($sender));
        $this->assertSame(40, $this->balanceOf($recipientAccount));
    }

    #[Test]
    public function a_transfer_beyond_the_balance_is_refused(): void
    {
        $sender = Account::factory()->create();
        $recipient = Account::factory()->create();

        Character::factory()->forAccount($recipient)->named('Friend')->create();

        $this->withCredits($sender, 10);

        $this->actingAs($sender)
            ->postJson('/api/account/credits/transfer', ['character' => 'Friend', 'credits' => 100])
            ->assertStatus(422);

        $this->assertSame(10, $this->balanceOf($sender));
        $this->assertSame(0, $this->balanceOf($recipient));
    }

    #[Test]
    public function credits_cannot_be_sent_to_your_own_account(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->named('Mine')->create();

        $this->withCredits($account, 100);

        $this->actingAs($account)
            ->postJson('/api/account/credits/transfer', ['character' => 'Mine', 'credits' => 10])
            ->assertStatus(422);

        $this->assertSame(100, $this->balanceOf($account));
    }

    #[Test]
    public function a_transfer_is_capped_when_the_operator_sets_one(): void
    {
        /*
         * FluxCP had no cap, which makes the panel a laundering route for
         * credits bought on one account and moved to another.
         */
        config()->set('panel.characters.credit_transfer.max_per_transfer', 50);

        $sender = Account::factory()->create();
        $recipient = Account::factory()->create();

        Character::factory()->forAccount($recipient)->named('Friend')->create();

        $this->withCredits($sender, 1000);

        $this->actingAs($sender)
            ->postJson('/api/account/credits/transfer', ['character' => 'Friend', 'credits' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credits');
    }

    #[Test]
    public function transfers_can_be_turned_off(): void
    {
        config()->set('panel.characters.credit_transfer.enabled', false);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/account/credits/transfer', ['character' => 'x', 'credits' => 1])
            ->assertStatus(403);
    }

    #[Test]
    public function the_transfer_history_shows_both_directions(): void
    {
        $sender = Account::factory()->create();
        $recipient = Account::factory()->create();

        Character::factory()->forAccount($recipient)->named('Friend')->create();

        $this->withCredits($sender, 100);

        $this->actingAs($sender)
            ->postJson('/api/account/credits/transfer', ['character' => 'Friend', 'credits' => 25])
            ->assertOk();

        $this->actingAs($sender)
            ->getJson('/api/account/credits/transfers')
            ->assertOk()
            ->assertJsonPath('data.0.direction', 'sent')
            ->assertJsonPath('data.0.amount', 25)
            ->assertJsonPath('data.0.to_character', 'Friend');

        // The same row read from the other end.
        $this->actingAs($recipient)
            ->getJson('/api/account/credits/transfers')
            ->assertOk()
            ->assertJsonPath('data.0.direction', 'received');
    }

    /*
    |--------------------------------------------------------------------------
    | Web commands
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function web_commands_are_off_by_default(): void
    {
        $this->actingAs(Account::factory()->create())
            ->postJson('/api/account/commands', ['command' => '@refresh'])
            ->assertStatus(403);
    }

    #[Test]
    public function only_allow_listed_commands_are_queued(): void
    {
        /*
         * cp_commands is read by a script with game-master powers, and the
         * legacy inserted whatever was submitted with no validation at all.
         */
        config()->set('panel.characters.web_commands.enabled', true);
        config()->set('panel.characters.web_commands.allowed', ['@refresh', '@storage*']);

        $account = Account::factory()->named('player')->create();

        $this->actingAs($account)
            ->postJson('/api/account/commands', ['command' => '@refresh'])
            ->assertCreated();

        $this->actingAs($account)
            ->postJson('/api/account/commands', ['command' => '@storageall'])
            ->assertCreated();

        foreach (['@item 501 100', '@zeny 999999', '@adjgroup 99', 'refresh'] as $command) {
            $this->actingAs($account)
                ->postJson('/api/account/commands', ['command' => $command])
                ->assertStatus(422);
        }

        $this->assertSame(
            2,
            DB::connection($this->charMap())->table('cp_commands')->count(),
        );
    }

    #[Test]
    public function an_empty_allow_list_accepts_nothing(): void
    {
        // So turning the feature on without configuring it is inert rather
        // than open.
        config()->set('panel.characters.web_commands.enabled', true);
        config()->set('panel.characters.web_commands.allowed', []);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/account/commands', ['command' => '@refresh'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_issuer_is_taken_from_the_session(): void
    {
        config()->set('panel.characters.web_commands.enabled', true);
        config()->set('panel.characters.web_commands.allowed', ['@refresh']);

        $account = Account::factory()->named('player')->create();

        $this->actingAs($account)
            ->postJson('/api/account/commands', [
                'command' => '@refresh',
                'issuer' => 'somebodyelse',
                'account_id' => 999999,
            ])->assertCreated();

        $row = DB::connection($this->charMap())->table('cp_commands')->first();

        $this->assertSame('player', $row->issuer);
        $this->assertSame($account->account_id, (int) $row->account_id);
    }

    /*
    |--------------------------------------------------------------------------
    | The script-controlled siege schedule
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_custom_siege_schedule_reads_the_script_variables(): void
    {
        DB::connection($this->charMap())->table('mapreg')->insert([
            ['varname' => '$sday', 'index' => 0, 'value' => '6'],
            ['varname' => '$eday', 'index' => 0, 'value' => '6'],
            ['varname' => '$stime', 'index' => 0, 'value' => '20'],
            ['varname' => '$etime', 'index' => 0, 'value' => '22'],
        ]);

        $this->getJson('/api/world/siege-schedule/custom')
            ->assertOk()
            ->assertJsonPath('meta.available', true)
            ->assertJsonPath('data.0.starts.day', 'Saturday')
            // Stored as a plain integer; rendered so it does not read "20"
            // next to a schedule that elsewhere says "20:00".
            ->assertJsonPath('data.0.starts.time', '20:00')
            ->assertJsonPath('data.0.ends.time', '22:00');
    }

    #[Test]
    public function a_server_without_the_convention_reports_an_empty_schedule(): void
    {
        $this->getJson('/api/world/siege-schedule/custom')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
