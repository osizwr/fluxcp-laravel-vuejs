<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * IP bans.
 *
 * Ports the coverage for ipban/index, add, edit, remove and unban.
 *
 * These write rAthena's own `ipbanlist`, so a mistake here locks people out of
 * the game and not just the website. The whitelist tests are the important
 * ones: a ban covering the operator's own range locks every administrator out
 * of both, and the only way back is editing the database by hand.
 */
final class IpBanTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('panel.ip_bans.whitelist', ['127.0.0.1', '0.*.*.*', '0.0.0.0']);
    }

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function asAdmin(): Account
    {
        $admin = Account::factory()->administrator()->create();

        $this->actingAs($admin);

        return $admin;
    }

    private function existingBan(string $pattern, string $reason = 'Botting'): void
    {
        DB::connection($this->loginConnection())->table('ipbanlist')->insert([
            'list' => $pattern,
            'reason' => $reason,
            'btime' => now(),
            'rtime' => now()->addDays(7),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_listing_is_administrator_only(): void
    {
        $this->getJson('/api/ip-bans')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/ip-bans')->assertStatus(403);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/ip-bans')->assertStatus(403);
    }

    #[Test]
    public function current_bans_are_listed_with_their_expiry(): void
    {
        $this->asAdmin();
        $this->existingBan('203.0.113.*');

        $this->getJson('/api/ip-bans')
            ->assertOk()
            ->assertJsonPath('data.0.pattern', '203.0.113.*')
            ->assertJsonPath('data.0.reason', 'Botting')
            ->assertJsonPath('data.0.expired', false);
    }

    #[Test]
    public function an_expired_ban_is_still_listed_but_flagged(): void
    {
        /*
         * rAthena stops enforcing the moment `rtime` passes, but an
         * administrator looking at this page wants to see what was banned
         * recently. Hiding them would make a lapsed ban look like it never
         * existed.
         */
        $this->asAdmin();

        DB::connection($this->loginConnection())->table('ipbanlist')->insert([
            'list' => '198.51.100.7', 'reason' => 'Old', 'btime' => now()->subDays(30), 'rtime' => now()->subDay(),
        ]);

        $this->getJson('/api/ip-bans')
            ->assertOk()
            ->assertJsonPath('data.0.expired', true);
    }

    #[Test]
    public function the_whitelist_is_published_with_the_listing(): void
    {
        // So the form can warn before submitting rather than only on rejection.
        $this->asAdmin();

        $this->getJson('/api/ip-bans')
            ->assertOk()
            ->assertJsonPath('meta.whitelist.0', '127.0.0.1');
    }

    /*
    |--------------------------------------------------------------------------
    | Adding
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_ban_can_be_added(): void
    {
        $admin = $this->asAdmin();

        $this->postJson('/api/ip-bans', [
            'pattern' => '203.0.113.*',
            'reason' => 'Botting',
            'days' => 7,
        ])->assertCreated();

        $this->assertDatabaseHas('ipbanlist', ['list' => '203.0.113.*'], $this->loginConnection());

        // The panel's own history is written too, with who did it.
        $this->assertDatabaseHas('cp_ipbanlog', [
            'ip_address' => '203.0.113.*',
            'banned_by' => $admin->account_id,
            'ban_type' => 1,
        ], $this->loginConnection());
    }

    #[Test]
    public function a_banned_address_can_no_longer_sign_in(): void
    {
        /*
         * The point of the whole module. Checked end to end rather than by
         * trusting that writing the row is enough.
         */
        /*
         * The test client connects from 127.0.0.1, which the default
         * whitelist protects — correctly. An operator whose panel is not on
         * localhost would not have it listed, so the whitelist is narrowed
         * here rather than the ban being forced past it.
         */
        config()->set('panel.ip_bans.whitelist', ['10.0.0.1']);

        $this->asAdmin();

        $this->postJson('/api/ip-bans', [
            'pattern' => '127.0.0.*',
            'reason' => 'Testing',
            'days' => 1,
        ])->assertCreated();

        $this->postJson('/api/auth/logout');

        Account::factory()->named('player')->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/auth/login', ['username' => 'player', 'password' => 'Zeny4Days!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', trans('auth.failure.ip_banned'));
    }

    #[Test]
    public function a_whitelisted_pattern_cannot_be_banned(): void
    {
        $this->asAdmin();

        $this->postJson('/api/ip-bans', [
            'pattern' => '127.0.0.1',
            'reason' => 'Oops',
            'days' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('pattern');

        $this->assertDatabaseCount('ipbanlist', 0, $this->loginConnection());
    }

    #[Test]
    public function a_pattern_covering_a_whitelisted_address_cannot_be_banned(): void
    {
        /*
         * The case the exact-match check misses, and the one that actually
         * locks everybody out: banning `127.*.*.*` rather than `127.0.0.1`.
         */
        $this->asAdmin();

        foreach (['127.*.*.*', '127.0.*.*', '127.0.0.*'] as $pattern) {
            $this->postJson('/api/ip-bans', [
                'pattern' => $pattern,
                'reason' => 'Oops',
                'days' => 7,
            ])->assertStatus(422)->assertJsonValidationErrors('pattern');
        }

        $this->assertDatabaseCount('ipbanlist', 0, $this->loginConnection());
    }

    #[Test]
    public function the_whitelist_is_a_glob_not_a_regular_expression(): void
    {
        /*
         * FluxCP interpolated this setting into a PCRE, so an operator writing
         * the obvious `192.168.*.*` got `.` matching any character and `*` as
         * a quantifier. Here it means what it looks like.
         */
        config()->set('panel.ip_bans.whitelist', ['192.168.*.*']);

        $this->asAdmin();

        $this->postJson('/api/ip-bans', ['pattern' => '192.168.1.*', 'reason' => 'x', 'days' => 1])
            ->assertStatus(422);

        // A regex reading would have matched this too, because `.` is any
        // character. A glob does not.
        $this->postJson('/api/ip-bans', ['pattern' => '192.169.1.1', 'reason' => 'x', 'days' => 1])
            ->assertCreated();
    }

    #[Test]
    public function a_malformed_pattern_is_refused(): void
    {
        $this->asAdmin();

        foreach ([
            '*.*.*.*',          // the first octet must be numeric
            '203.0.113',        // three octets
            '203.0.113.256',    // out of range
            'not-an-address',
            '203.0.113.*/24',
        ] as $pattern) {
            $this->postJson('/api/ip-bans', ['pattern' => $pattern, 'reason' => 'x', 'days' => 1])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('ipbanlist', 0, $this->loginConnection());
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->asAdmin();

        $this->postJson('/api/ip-bans', ['pattern' => '203.0.113.*', 'days' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function banning_a_pattern_twice_replaces_it_rather_than_failing(): void
    {
        $this->asAdmin();
        $this->existingBan('203.0.113.*', 'First reason');

        $this->postJson('/api/ip-bans', [
            'pattern' => '203.0.113.*',
            'reason' => 'Second reason',
            'days' => 30,
        ])->assertCreated();

        $rows = DB::connection($this->loginConnection())->table('ipbanlist')
            ->where('list', '203.0.113.*')->get();

        $this->assertCount(1, $rows, 'Re-banning should leave one live entry, not two.');
        $this->assertSame('Second reason', $rows[0]->reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_ban_can_be_edited(): void
    {
        $this->asAdmin();
        $this->existingBan('203.0.113.7');

        $this->putJson('/api/ip-bans/'.urlencode('203.0.113.7'), [
            'pattern' => '203.0.113.*',
            'reason' => 'Widened after more reports',
            'edit_reason' => 'Whole subnet involved',
            'days' => 30,
        ])->assertOk();

        $this->assertDatabaseMissing('ipbanlist', ['list' => '203.0.113.7'], $this->loginConnection());
        $this->assertDatabaseHas('ipbanlist', ['list' => '203.0.113.*'], $this->loginConnection());
    }

    #[Test]
    public function an_edit_is_recorded_in_the_history(): void
    {
        /*
         * Without this, an administrator widening a ban from one address to a
         * whole /8 leaves no trace of who did it or why.
         */
        $this->asAdmin();
        $this->existingBan('203.0.113.7');

        $this->putJson('/api/ip-bans/'.urlencode('203.0.113.7'), [
            'pattern' => '203.*.*.*',
            'reason' => 'Widened',
            'edit_reason' => 'Whole range involved',
            'days' => 30,
        ])->assertOk();

        $row = DB::connection($this->loginConnection())->table('cp_ipbanlog')
            ->where('ip_address', '203.*.*.*')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('203.0.113.7', $row->ban_reason);
        $this->assertStringContainsString('Whole range involved', $row->ban_reason);
    }

    #[Test]
    public function editing_a_ban_that_does_not_exist_is_a_404(): void
    {
        $this->asAdmin();

        $this->putJson('/api/ip-bans/'.urlencode('203.0.113.7'), [
            'pattern' => '203.0.113.8', 'reason' => 'x', 'days' => 1,
        ])->assertNotFound();
    }

    #[Test]
    public function an_edit_cannot_widen_a_ban_onto_the_whitelist(): void
    {
        $this->asAdmin();
        $this->existingBan('203.0.113.7');

        $this->putJson('/api/ip-bans/'.urlencode('203.0.113.7'), [
            'pattern' => '127.0.0.*', 'reason' => 'x', 'days' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('pattern');

        $this->assertDatabaseHas('ipbanlist', ['list' => '203.0.113.7'], $this->loginConnection());
    }

    /*
    |--------------------------------------------------------------------------
    | Lifting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function bans_can_be_lifted_in_a_batch(): void
    {
        $this->asAdmin();
        $this->existingBan('203.0.113.*');
        $this->existingBan('198.51.100.*');

        $this->deleteJson('/api/ip-bans', [
            'patterns' => ['203.0.113.*', '198.51.100.*'],
            'reason' => 'Incident resolved',
        ])->assertOk()->assertJsonCount(2, 'lifted');

        $this->assertDatabaseCount('ipbanlist', 0, $this->loginConnection());
    }

    #[Test]
    public function lifting_records_the_history_and_removes_the_live_row(): void
    {
        /*
         * ipbanlist is current state the login server reads, so the row goes.
         * cp_ipbanlog is the record that it ever existed, so a row is added.
         */
        $admin = $this->asAdmin();
        $this->existingBan('203.0.113.*');

        $this->deleteJson('/api/ip-bans', [
            'patterns' => ['203.0.113.*'],
            'reason' => 'Appeal upheld',
        ])->assertOk();

        $this->assertDatabaseMissing('ipbanlist', ['list' => '203.0.113.*'], $this->loginConnection());

        $this->assertDatabaseHas('cp_ipbanlog', [
            'ip_address' => '203.0.113.*',
            'banned_by' => $admin->account_id,
            'ban_type' => 0,
            'ban_reason' => 'Appeal upheld',
        ], $this->loginConnection());
    }

    #[Test]
    public function a_pattern_that_was_not_banned_is_reported_rather_than_ignored(): void
    {
        // Somebody may be working from a stale list.
        $this->asAdmin();
        $this->existingBan('203.0.113.*');

        $this->deleteJson('/api/ip-bans', [
            'patterns' => ['203.0.113.*', '198.51.100.*'],
            'reason' => 'Tidying up',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'lifted')
            ->assertJsonPath('not_found.0', '198.51.100.*');
    }

    #[Test]
    public function lifting_requires_a_reason(): void
    {
        $this->asAdmin();
        $this->existingBan('203.0.113.*');

        $this->deleteJson('/api/ip-bans', ['patterns' => ['203.0.113.*']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('ipbanlist', ['list' => '203.0.113.*'], $this->loginConnection());
    }

    /*
    |--------------------------------------------------------------------------
    | Abilities
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function writing_needs_the_matching_ability_on_top_of_the_route_level(): void
    {
        /*
         * The route is held at Administrator, and the abilities are separate so
         * an operator can grant read access to the ban list without granting
         * the ability to change it.
         */
        Gate::define('ModifyIpBan', static fn (): bool => false);
        Gate::define('RemoveIpBan', static fn (): bool => false);

        $this->asAdmin();
        $this->existingBan('203.0.113.*');

        $this->postJson('/api/ip-bans', ['pattern' => '198.51.100.*', 'reason' => 'x', 'days' => 1])
            ->assertStatus(403);

        $this->deleteJson('/api/ip-bans', ['patterns' => ['203.0.113.*'], 'reason' => 'x'])
            ->assertStatus(403);

        // Reading is still allowed.
        $this->getJson('/api/ip-bans')->assertOk();
    }
}
