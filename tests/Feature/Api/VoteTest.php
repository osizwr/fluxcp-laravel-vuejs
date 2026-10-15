<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\VoteLog;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Voting for the server, and being paid for it.
 *
 * This is the one endpoint in the panel a stranger is invited to call, and it
 * hands out the currency the item shop spends -- so most of what is below is
 * about what it refuses. The three that matter are replaying a reference,
 * guessing a secret, and claiming a vote on behalf of somebody else.
 */
final class VoteTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const SECRET = 'a-shared-secret-from-the-listing-site';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vote.enabled' => true,
            'vote.cooldown_hours' => 12,
            'vote.reference_minutes' => 60,
            'vote.sites' => [
                'gtop100' => [
                    'name' => 'Gtop100',
                    'url' => 'https://gtop100.test/vote?id=7&Custom={reference}',
                    'secret' => self::SECRET,
                    'credits' => 5,
                ],
            ],
        ]);
    }

    private function player(): Account
    {
        return Account::factory()->create();
    }

    /** Credits live in rAthena's login database, as the shop and donations do. */
    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function balance(Account $account): int
    {
        return (int) (DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)
            ->value('balance') ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Reaching it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_vote_page_needs_an_account(): void
    {
        $this->getJson('/api/vote')->assertStatus(401);

        $this->actingAs($this->player())->getJson('/api/vote')->assertOk();
    }

    /**
     * Switched off is gone entirely: no page, and no menu entry drawn from it.
     */
    #[Test]
    public function it_is_absent_when_an_operator_switches_it_off(): void
    {
        config(['vote.enabled' => false]);

        $this->actingAs($this->player())->getJson('/api/vote')->assertStatus(404);
        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => 'anything',
            'token' => self::SECRET,
        ])->assertStatus(404);
    }

    /**
     * Switched on with nothing registered yet is an empty list, not a 404.
     *
     * That is the order an operator sets this up in -- turn it on, then paste
     * in the first site -- and a feature that hid itself in between would
     * leave them with no way to see what they were configuring.
     */
    #[Test]
    public function it_answers_with_an_empty_list_before_a_site_is_registered(): void
    {
        config(['vote.sites' => []]);

        $this->actingAs($this->player())
            ->getJson('/api/vote')
            ->assertOk()
            ->assertJsonCount(0, 'data.sites')
            ->assertJsonPath('data.credits', 0);
    }

    /**
     * A site that cannot pay anybody is not offered.
     *
     * Half-configured is the normal state of a site somebody is in the middle
     * of setting up, and a button that cannot work is worse than no button --
     * so the page is reached, and that site is simply not on it.
     */
    #[Test]
    public function a_site_with_no_secret_is_not_listed(): void
    {
        config(['vote.sites.gtop100.secret' => '']);

        $this->actingAs($this->player())
            ->getJson('/api/vote')
            ->assertOk()
            ->assertJsonCount(0, 'data.sites');

        // And it cannot be voted on by asking for it directly.
        $this->actingAs($this->player())->postJson('/api/vote/gtop100')->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_the_sites_with_the_balance(): void
    {
        $account = $this->player();

        DB::connection($this->loginConnection())->table('cp_credits')
            ->insert(['account_id' => $account->account_id, 'balance' => 40]);

        $this->actingAs($account)
            ->getJson('/api/vote')
            ->assertOk()
            ->assertJsonPath('data.credits', 40)
            ->assertJsonPath('data.sites.0.key', 'gtop100')
            ->assertJsonPath('data.sites.0.name', 'Gtop100')
            ->assertJsonPath('data.sites.0.credits', 5)
            ->assertJsonPath('data.sites.0.ready', true);
    }

    /**
     * The secret is the one field that must never leave the server.
     */
    #[Test]
    public function the_listing_never_carries_the_secret(): void
    {
        $body = $this->actingAs($this->player())->getJson('/api/vote')->assertOk()->content();

        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Starting a vote
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function starting_a_vote_mints_a_reference_and_points_at_the_site(): void
    {
        $account = $this->player();

        $url = (string) $this->actingAs($account)
            ->postJson('/api/vote/gtop100')
            ->assertOk()
            ->json('data.url');

        $vote = VoteLog::query()->sole();

        $this->assertSame($account->account_id, $vote->account_id);
        $this->assertSame('gtop100', $vote->site);
        $this->assertNull($vote->claimed_at);
        $this->assertSame(0, $vote->credits);

        // The reference is substituted into whatever the site calls "custom".
        $this->assertSame('https://gtop100.test/vote?id=7&Custom='.$vote->reference, $url);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $vote->reference);
    }

    #[Test]
    public function starting_a_vote_pays_nothing_by_itself(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $this->assertSame(0, $this->balance($account));
    }

    #[Test]
    public function an_unknown_site_cannot_be_started(): void
    {
        $this->actingAs($this->player())
            ->postJson('/api/vote/not-a-site')
            ->assertStatus(422);

        $this->assertSame(0, VoteLog::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Being paid
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_callback_pays_the_account_that_started_the_vote(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $reference = VoteLog::query()->sole()->reference;

        // Called as the listing site calls it: no session, no CSRF token.
        $this->postJson('/api/vote/gtop100/callback', [
            'Custom' => $reference,
            'token' => self::SECRET,
        ])->assertOk()->assertJsonPath('recorded', true);

        $this->assertSame(5, $this->balance($account));

        $vote = VoteLog::query()->sole();

        $this->assertNotNull($vote->claimed_at);
        $this->assertSame(5, $vote->credits);
    }

    /**
     * Listing sites disagree about the spelling, and about the verb.
     */
    #[Test]
    public function a_callback_is_accepted_however_the_site_spells_it(): void
    {
        $account = $this->player();

        foreach (['custom', 'Custom', 'reference', 'voter_id'] as $index => $name) {
            config(['vote.sites.gtop100.cooldown' => 0]);

            $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

            $reference = VoteLog::query()->latest('id')->firstOrFail()->reference;

            $this->getJson("/api/vote/gtop100/callback?{$name}={$reference}&key=".self::SECRET)
                ->assertOk()
                ->assertJsonPath('recorded', true);

            $this->assertSame(5 * ($index + 1), $this->balance($account));
        }
    }

    /**
     * A retry is not a second vote.
     *
     * Listing sites redeliver, sometimes for days. The reference is spent the
     * moment it pays, so the redelivery is answered politely and paid once.
     */
    #[Test]
    public function the_same_callback_twice_pays_once(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $reference = VoteLog::query()->sole()->reference;
        $payload = ['custom' => $reference, 'token' => self::SECRET];

        $this->postJson('/api/vote/gtop100/callback', $payload)->assertJsonPath('recorded', true);
        $this->postJson('/api/vote/gtop100/callback', $payload)->assertJsonPath('recorded', false);

        $this->assertSame(5, $this->balance($account));
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    /**
     * Without the secret the endpoint is a free credit machine.
     */
    #[Test]
    public function a_callback_with_the_wrong_secret_pays_nothing(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $reference = VoteLog::query()->sole()->reference;

        foreach (['', 'wrong', self::SECRET.'x', substr(self::SECRET, 0, -1)] as $secret) {
            $this->postJson('/api/vote/gtop100/callback', [
                'custom' => $reference,
                'token' => $secret,
            ])->assertOk()->assertJsonPath('recorded', false);
        }

        $this->assertSame(0, $this->balance($account));
        $this->assertNull(VoteLog::query()->sole()->claimed_at);
    }

    /**
     * A reference nobody minted is worth nothing, secret or no secret.
     */
    #[Test]
    public function a_callback_with_an_invented_reference_pays_nothing(): void
    {
        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => str_repeat('a', 32),
            'token' => self::SECRET,
        ])->assertOk()->assertJsonPath('recorded', false);

        $this->assertSame(0, (int) (DB::connection($this->loginConnection())
            ->table('cp_credits')->sum('balance') ?? 0));
    }

    /**
     * One site's secret does not open another's callback.
     */
    #[Test]
    public function a_reference_cannot_be_claimed_on_a_different_site(): void
    {
        config(['vote.sites.other' => [
            'name' => 'Other',
            'url' => 'https://other.test/?c={reference}',
            'secret' => 'a-different-secret',
            'credits' => 50,
        ]]);

        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $reference = VoteLog::query()->sole()->reference;

        $this->postJson('/api/vote/other/callback', [
            'custom' => $reference,
            'token' => 'a-different-secret',
        ])->assertOk()->assertJsonPath('recorded', false);

        $this->assertSame(0, $this->balance($account));
    }

    #[Test]
    public function a_reference_expires(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $vote = VoteLog::query()->sole();

        $this->travel(61)->minutes();

        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => $vote->reference,
            'token' => self::SECRET,
        ])->assertOk()->assertJsonPath('recorded', false);

        $this->assertSame(0, $this->balance($account));
    }

    /*
    |--------------------------------------------------------------------------
    | The cooldown
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_second_vote_inside_the_cooldown_pays_nothing(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $first = VoteLog::query()->sole()->reference;

        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => $first,
            'token' => self::SECRET,
        ])->assertJsonPath('recorded', true);

        // Starting again is refused outright, which is what the page shows.
        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertStatus(422);

        $this->actingAs($account)
            ->getJson('/api/vote')
            ->assertJsonPath('data.sites.0.ready', false)
            ->assertJsonPath('data.sites.0.next_at', fn (?string $at): bool => $at !== null);

        $this->assertSame(5, $this->balance($account));
    }

    #[Test]
    public function the_cooldown_lets_go_once_it_has_passed(): void
    {
        $account = $this->player();

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => VoteLog::query()->sole()->reference,
            'token' => self::SECRET,
        ])->assertJsonPath('recorded', true);

        $this->travel(13)->hours();

        $this->actingAs($account)
            ->getJson('/api/vote')
            ->assertJsonPath('data.sites.0.ready', true)
            ->assertJsonPath('data.sites.0.next_at', null);

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => VoteLog::query()->latest('id')->firstOrFail()->reference,
            'token' => self::SECRET,
        ])->assertJsonPath('recorded', true);

        $this->assertSame(10, $this->balance($account));
    }

    /**
     * One player's cooldown is not another's.
     */
    #[Test]
    public function the_cooldown_is_per_account(): void
    {
        $first = $this->player();
        $second = $this->player();

        foreach ([$first, $second] as $account) {
            $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

            $this->postJson('/api/vote/gtop100/callback', [
                'custom' => VoteLog::query()->latest('id')->firstOrFail()->reference,
                'token' => self::SECRET,
            ])->assertJsonPath('recorded', true);
        }

        $this->assertSame(5, $this->balance($first));
        $this->assertSame(5, $this->balance($second));
    }

    /**
     * A vote credits an account with no balance row yet.
     *
     * The row is created on first donation rather than with the account, so
     * for most players their first vote is the first time anything has needed
     * one at all.
     */
    #[Test]
    public function a_first_vote_creates_the_balance_row(): void
    {
        $account = $this->player();

        $this->assertFalse(DB::connection($this->loginConnection())->table('cp_credits')
            ->where('account_id', $account->account_id)->exists());

        $this->actingAs($account)->postJson('/api/vote/gtop100')->assertOk();

        $this->postJson('/api/vote/gtop100/callback', [
            'custom' => VoteLog::query()->sole()->reference,
            'token' => self::SECRET,
        ])->assertJsonPath('recorded', true);

        $this->assertSame(5, $this->balance($account));
    }
}
