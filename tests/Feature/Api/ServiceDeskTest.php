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
 * Support tickets.
 *
 * Ports the coverage for the eight servicedesk actions.
 *
 * The ownership tests are the important ones. A ticket contains whatever
 * somebody typed while frustrated — often more than they would say publicly —
 * and the legacy `create` action took the ticket's owner straight out of the
 * submitted form.
 */
final class ServiceDeskTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function category(string $name = 'Bug report', bool $visible = true): int
    {
        return (int) DB::connection($this->loginConnection())->table('cp_servicedeskcat')->insertGetId([
            'name' => $name, 'display' => $visible ? 1 : 0,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Opening a ticket
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_player_can_open_a_ticket(): void
    {
        $account = Account::factory()->create();
        $category = $this->category();

        $this->actingAs($account)
            ->postJson('/api/support/tickets', [
                'category' => $category,
                'subject' => 'Lost an item',
                'text' => 'It vanished after a server restart.',
            ])->assertCreated();

        $this->assertDatabaseHas('cp_servicedesk', [
            'account_id' => $account->account_id,
            'subject' => 'Lost an item',
            'status' => 'Pending',
        ], $this->loginConnection());
    }

    #[Test]
    public function the_ticket_owner_cannot_be_forged(): void
    {
        /*
         * The legacy insert read `$_POST['account_id']` and `$_POST['ip']`, so
         * a player could open a ticket as somebody else and record whatever
         * address they liked against it.
         */
        $account = Account::factory()->create();
        $victim = Account::factory()->create();

        $this->actingAs($account)
            ->postJson('/api/support/tickets', [
                'account_id' => $victim->account_id,
                'ip' => '203.0.113.99',
                'category' => $this->category(),
                'subject' => 'Not mine',
                'text' => 'x',
            ])->assertCreated();

        $row = DB::connection($this->loginConnection())->table('cp_servicedesk')->first();

        $this->assertSame($account->account_id, (int) $row->account_id);
        $this->assertNotSame('203.0.113.99', $row->ip);
    }

    #[Test]
    public function a_hidden_category_cannot_be_chosen(): void
    {
        $hidden = $this->category('Internal', visible: false);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/support/tickets', [
                'category' => $hidden, 'subject' => 'x', 'text' => 'y',
            ])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    #[Test]
    public function a_character_must_belong_to_the_account(): void
    {
        // Otherwise the field attaches somebody else's character to your
        // complaint.
        $account = Account::factory()->create();
        $theirs = Character::factory()->forAccount(Account::factory()->create())->create();

        $this->actingAs($account)
            ->postJson('/api/support/tickets', [
                'category' => $this->category(),
                'subject' => 'x', 'text' => 'y',
                'char_id' => $theirs->char_id,
            ])->assertStatus(422);
    }

    #[Test]
    public function a_supporting_link_must_be_http(): void
    {
        $this->actingAs(Account::factory()->create())
            ->postJson('/api/support/tickets', [
                'category' => $this->category(),
                'subject' => 'x', 'text' => 'y',
                'sslink' => 'javascript:alert(1)',
            ])->assertStatus(422)->assertJsonValidationErrors('sslink');
    }

    /*
    |--------------------------------------------------------------------------
    | Reading tickets
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_player_sees_only_their_own_tickets(): void
    {
        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();
        $category = $this->category();

        foreach ([[$mine, 'Mine'], [$theirs, 'Theirs']] as [$account, $subject]) {
            DB::connection($this->loginConnection())->table('cp_servicedesk')->insert([
                'account_id' => $account->account_id, 'category' => $category,
                'char_id' => '0', 'subject' => $subject, 'text' => 'x',
                'timestamp' => now(), 'status' => 'Pending', 'lastreply' => '0',
            ]);
        }

        $this->actingAs($mine)
            ->getJson('/api/support/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Mine');
    }

    #[Test]
    public function reading_another_players_ticket_is_a_404_not_a_403(): void
    {
        /*
         * A 403 confirms the ticket exists, which over a range of ids is a
         * count of how many tickets the server has ever had.
         */
        $theirs = Account::factory()->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $theirs->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'Theirs', 'text' => 'x',
            'timestamp' => now(), 'status' => 'Pending', 'lastreply' => '0',
        ]);

        $this->actingAs(Account::factory()->create())
            ->getJson("/api/support/tickets/{$id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_ticket_shows_its_replies(): void
    {
        $account = Account::factory()->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'Help', 'text' => 'Please',
            'timestamp' => now(), 'status' => 'Pending', 'lastreply' => '0',
        ]);

        DB::connection($this->loginConnection())->table('cp_servicedeska')->insert([
            'ticket_id' => $id, 'author' => 'Support', 'text' => 'Looking into it.',
            'timestamp' => now(), 'ip' => '198.51.100.1', 'isstaff' => 1,
        ]);

        $response = $this->actingAs($account)
            ->getJson("/api/support/tickets/{$id}")
            ->assertOk();

        $response->assertJsonPath('data.replies.0.author', 'Support')
            ->assertJsonPath('data.replies.0.from_staff', true);

        // A reply's address is for the log browser, not for the other party.
        $this->assertStringNotContainsString('198.51.100.1', $response->getContent());
    }

    #[Test]
    public function an_empty_link_field_is_reported_as_absent(): void
    {
        // The legacy wrote the string '0' for an empty link field, so that
        // value means "none" rather than being a URL.
        $account = Account::factory()->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Pending', 'lastreply' => '0',
            'sslink' => '0', 'chatlink' => '', 'videolink' => 'https://example.test/clip',
        ]);

        $this->actingAs($account)
            ->getJson("/api/support/tickets/{$id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.links')
            ->assertJsonPath('data.links.0', 'https://example.test/clip');
    }

    /*
    |--------------------------------------------------------------------------
    | Replying
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_player_reply_moves_the_ticket_back_into_the_queue(): void
    {
        /*
         * Otherwise a ticket somebody has replied to sits in "Answered",
         * where nobody looks.
         */
        $account = Account::factory()->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Answered', 'lastreply' => 'Support',
        ]);

        $this->actingAs($account)
            ->postJson("/api/support/tickets/{$id}/replies", ['text' => 'Still broken.'])
            ->assertOk();

        $this->assertDatabaseHas('cp_servicedesk', [
            'ticket_id' => $id, 'status' => 'In Progress',
        ], $this->loginConnection());
    }

    #[Test]
    public function a_closed_ticket_cannot_be_replied_to(): void
    {
        $account = Account::factory()->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Closed', 'lastreply' => '0',
        ]);

        $this->actingAs($account)
            ->postJson("/api/support/tickets/{$id}/replies", ['text' => 'Hello?'])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | The staff side
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_queue_is_staff_only(): void
    {
        $this->actingAs(Account::factory()->create())
            ->getJson('/api/support/queue')->assertStatus(403);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/support/queue')->assertOk();
    }

    #[Test]
    public function the_queue_separates_open_from_closed(): void
    {
        $account = Account::factory()->create();
        $category = $this->category();

        foreach ([['Open one', 'Pending'], ['Done', 'Closed']] as [$subject, $status]) {
            DB::connection($this->loginConnection())->table('cp_servicedesk')->insert([
                'account_id' => $account->account_id, 'category' => $category,
                'char_id' => '0', 'subject' => $subject, 'text' => 'x',
                'timestamp' => now(), 'status' => $status, 'lastreply' => '0',
            ]);
        }

        $staff = Account::factory()->juniorGameMaster()->create();

        $this->actingAs($staff)->getJson('/api/support/queue')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Open one');

        $this->actingAs($staff)->getJson('/api/support/queue/closed')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Done');
    }

    #[Test]
    public function the_closed_queue_cannot_be_used_to_read_the_live_one(): void
    {
        // The route default wins over the query parameter, so the archive's
        // permission cannot be used to reach the live queue.
        $account = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_servicedesk')->insert([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'Open one', 'text' => 'x',
            'timestamp' => now(), 'status' => 'Pending', 'lastreply' => '0',
        ]);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/support/queue/closed?closed=0')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function staff_can_reply_and_close_in_one_action(): void
    {
        $account = Account::factory()->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Pending', 'lastreply' => '0',
        ]);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Restored the item.',
                'status' => 'Closed',
            ])->assertOk();

        $this->assertDatabaseHas('cp_servicedesk', [
            'ticket_id' => $id, 'status' => 'Closed',
        ], $this->loginConnection());
    }

    #[Test]
    public function staff_replies_are_signed_with_their_chosen_name(): void
    {
        /*
         * So staff are not required to expose their account name to every
         * player who opens a ticket.
         */
        $account = Account::factory()->create();
        $staff = Account::factory()->juniorGameMaster()->named('realname')->create();

        $this->actingAs($staff)
            ->putJson('/api/support/settings', ['display_name' => 'Support Team'])
            ->assertOk();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Pending', 'lastreply' => '0',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/support/queue/{$id}/replies", ['text' => 'Hello.'])
            ->assertOk();

        $response = $this->actingAs($account)->getJson("/api/support/tickets/{$id}")->assertOk();

        $this->assertSame('Support Team', $response->json('data.replies.0.author'));
        $this->assertStringNotContainsString('realname', $response->getContent());
    }

    #[Test]
    public function staff_see_details_a_player_does_not(): void
    {
        $account = Account::factory()->state(['email' => 'player@example.test'])->create();

        $id = DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Pending', 'lastreply' => '0',
            'ip' => '198.51.100.44', 'curemail' => 'player@example.test',
        ]);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson("/api/support/queue/{$id}")
            ->assertOk()
            ->assertJsonPath('data.opened_from', '198.51.100.44')
            ->assertJsonPath('data.account_email', 'player@example.test');

        // The owner's own view does not carry them.
        $this->actingAs($account)
            ->getJson("/api/support/tickets/{$id}")
            ->assertOk()
            ->assertJsonMissingPath('data.opened_from');
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function categories_are_managed_by_senior_staff(): void
    {
        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/support/categories')->assertStatus(403);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson('/api/support/categories', ['name' => 'Billing'])
            ->assertCreated();

        $this->assertDatabaseHas('cp_servicedeskcat', ['name' => 'Billing'], $this->loginConnection());
    }

    /**
     * A migrated FluxCP install has tickets in these states, so the port has
     * to be able to set and recognise them. An earlier revision listed two
     * statuses the legacy never wrote and omitted `Resolved`, which it did.
     */
    #[Test]
    public function the_legacy_statuses_are_all_settable_and_keep_a_ticket_queued(): void
    {
        $player = Account::factory()->create();
        $staff = Account::factory()->seniorGameMaster()->create();

        foreach (['Pending', 'Resolved', 'Closed'] as $status) {
            $id = $this->ticketFor($player);

            $this->actingAs($staff)
                ->postJson("/api/support/queue/{$id}/replies", [
                    'text' => 'Noted.', 'status' => $status,
                ])
                ->assertOk();

            $this->assertDatabaseHas('cp_servicedesk', [
                'ticket_id' => $id, 'status' => $status,
            ], $this->loginConnection());
        }

        /*
         * Everything but Closed stays in the queue, which is the legacy's own
         * test: its queue queries all read `status != 'Closed'`.
         */
        $queued = $this->actingAs($staff)->getJson('/api/support/queue')
            ->assertOk()
            ->json('data');

        $statuses = array_column($queued, 'status');

        $this->assertContains('Resolved', $statuses);
        $this->assertContains('Pending', $statuses);
        $this->assertNotContains('Closed', $statuses);
    }

    /*
    |--------------------------------------------------------------------------
    | Credit rewards
    |--------------------------------------------------------------------------
    |
    | FluxCP's SDEnableCreditRewards: staff may award credits to a player who
    | reported a bug or an abuse.
    |
    */

    private function ticketFor(Account $account): int
    {
        return (int) DB::connection($this->loginConnection())->table('cp_servicedesk')->insertGetId([
            'account_id' => $account->account_id, 'category' => $this->category(),
            'char_id' => '0', 'subject' => 'x', 'text' => 'y', 'timestamp' => now(),
            'status' => 'Pending', 'lastreply' => '0',
        ]);
    }

    private function balanceOf(Account $account): int
    {
        return (int) (DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)
            ->value('balance') ?? 0);
    }

    #[Test]
    public function staff_can_award_credits_when_replying(): void
    {
        $player = Account::factory()->create();
        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks for the report.',
                'status' => 'Resolved',
                'award_credits' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('data.credits_awarded', 25);

        $this->assertSame(25, $this->balanceOf($player));
    }

    #[Test]
    public function an_award_adds_to_an_existing_balance(): void
    {
        $player = Account::factory()->create();

        DB::connection($this->loginConnection())->table('cp_credits')->insert([
            'account_id' => $player->account_id, 'balance' => 100,
        ]);

        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks.', 'award_credits' => 10,
            ])
            ->assertOk();

        $this->assertSame(110, $this->balanceOf($player));
    }

    /**
     * The legacy credited `$_POST['account_id']`, so the account to be paid
     * was whatever the form said. It is read from the ticket here.
     */
    #[Test]
    public function the_award_goes_to_the_ticket_owner_not_a_submitted_account(): void
    {
        $owner = Account::factory()->create();
        $other = Account::factory()->create();

        $id = $this->ticketFor($owner);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks.',
                'award_credits' => 50,
                'account_id' => $other->account_id,
            ])
            ->assertOk();

        $this->assertSame(50, $this->balanceOf($owner));
        $this->assertSame(0, $this->balanceOf($other));
    }

    /**
     * The legacy had no ceiling, so a mistyped figure awarded a fortune.
     */
    #[Test]
    public function an_award_above_the_cap_is_refused(): void
    {
        config(['panel.service_desk.credit_rewards.maximum' => 100]);

        $player = Account::factory()->create();
        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks.', 'award_credits' => 100_000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('award_credits');

        $this->assertSame(0, $this->balanceOf($player));
    }

    #[Test]
    public function an_award_is_refused_when_rewards_are_turned_off(): void
    {
        config(['panel.service_desk.credit_rewards.enabled' => false]);

        $player = Account::factory()->create();
        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks.', 'award_credits' => 5,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $this->balanceOf($player));
    }

    /**
     * Handing out currency is a separate act from answering a question, so it
     * has its own ability -- a junior game master may reply and not award.
     */
    #[Test]
    public function replying_staff_without_the_ability_cannot_award(): void
    {
        $player = Account::factory()->create();
        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks.', 'award_credits' => 5,
            ])
            ->assertStatus(403);

        $this->assertSame(0, $this->balanceOf($player));
    }

    #[Test]
    public function a_reply_without_an_award_leaves_the_balance_alone(): void
    {
        $player = Account::factory()->create();
        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", ['text' => 'Looking into it.'])
            ->assertOk()
            ->assertJsonPath('data.credits_awarded', 0);

        $this->assertSame(0, $this->balanceOf($player));
    }

    /**
     * The reply's action note is the only record of an award on the ticket
     * itself, so it says so.
     */
    #[Test]
    public function an_award_is_recorded_on_the_reply_and_in_the_transaction_log(): void
    {
        $player = Account::factory()->create();
        $id = $this->ticketFor($player);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson("/api/support/queue/{$id}/replies", [
                'text' => 'Thanks.', 'status' => 'Resolved', 'award_credits' => 15,
            ])
            ->assertOk();

        $note = (string) DB::connection($this->loginConnection())->table('cp_servicedeska')
            ->where('ticket_id', $id)
            ->value('action');

        $this->assertStringContainsString('15 credits awarded', $note);
        $this->assertStringContainsString('Resolved', $note);

        $log = DB::connection($this->loginConnection())->table('cp_txnlog')
            ->where('account_id', $player->account_id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(15, (int) $log->credits);
        $this->assertSame('service_desk_reward', $log->txn_type);
        // cp_txnlog.txn_id is varchar(20), and these connections are
        // non-strict, so a longer value is truncated rather than refused.
        $this->assertLessThanOrEqual(20, strlen((string) $log->txn_id));
    }

    #[Test]
    public function a_category_is_hidden_rather_than_deleted(): void
    {
        /*
         * Tickets reference a category by id, so removing the row would leave
         * historic tickets pointing at nothing.
         */
        $id = $this->category('Obsolete');

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson('/api/support/categories', ['id' => $id, 'hide' => true])
            ->assertOk();

        $this->assertDatabaseHas('cp_servicedeskcat', [
            'cat_id' => $id, 'display' => 0,
        ], $this->loginConnection());
    }
}
