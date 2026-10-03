<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\BroadcastMessage;
use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The broadcast mailer and the terms of service.
 *
 * Ports the coverage for mail/index and service/tos.
 */
final class BroadcastAndTermsTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    /*
    |--------------------------------------------------------------------------
    | Broadcast
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function broadcasting_is_administrator_only(): void
    {
        $this->postJson('/api/admin/broadcast', [
            'subject' => 'x', 'body' => 'y', 'audience' => 'everyone',
        ])->assertStatus(401);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson('/api/admin/broadcast', [
                'subject' => 'x', 'body' => 'y', 'audience' => 'everyone',
            ])->assertStatus(403);
    }

    #[Test]
    public function the_first_request_reports_the_count_and_sends_nothing(): void
    {
        /*
         * Sending to everyone cannot be stopped once it starts and is visible
         * to every player at once. A caller that has not looked at the count
         * cannot accidentally send.
         */
        Account::factory()->count(3)->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Maintenance',
                'body' => 'We will be offline on Friday.',
                'audience' => 'everyone',
            ])
            ->assertOk()
            ->assertJsonPath('data.sent', false);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function confirming_the_count_sends_the_messages(): void
    {
        Account::factory()->count(3)->create();

        $admin = Account::factory()->administrator()->create();

        $count = $this->actingAs($admin)
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Maintenance', 'body' => 'Friday.', 'audience' => 'everyone',
            ])->json('data.recipients');

        $this->actingAs($admin)
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Maintenance',
                'body' => 'Friday.',
                'audience' => 'everyone',
                'confirm_recipients' => $count,
            ])
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.queued', $count);

        Mail::assertQueued(BroadcastMessage::class, $count);
    }

    #[Test]
    public function a_changed_recipient_count_refuses_rather_than_sends(): void
    {
        /*
         * Somebody registered between looking and sending. Refusing makes the
         * sender look again rather than send to a list they have not seen.
         */
        Account::factory()->count(2)->create();

        $admin = Account::factory()->administrator()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/broadcast', [
                'subject' => 'x', 'body' => 'y', 'audience' => 'everyone',
                'confirm_recipients' => 99,
            ])
            ->assertStatus(409)
            ->assertJsonPath('data.sent', false);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_single_address_can_be_mailed(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Hello',
                'body' => 'Just you.',
                'audience' => 'address',
                'address' => 'one@example.test',
                'confirm_recipients' => 1,
            ])->assertOk();

        Mail::assertQueued(BroadcastMessage::class, 1);
    }

    #[Test]
    public function server_accounts_and_unusable_addresses_are_excluded(): void
    {
        /*
         * rAthena's own inter-server accounts have no human owner, and an old
         * install has rows with empty or placeholder e-mail columns. Trying to
         * send to them is how a run fails halfway.
         */
        Account::factory()->state(['email' => 'real@example.test'])->create();
        Account::factory()->serverAccount()->state(['email' => 'server@example.test'])->create();
        Account::factory()->state(['email' => ''])->create();
        Account::factory()->state(['email' => 'not-an-address'])->create();

        $this->actingAs(Account::factory()->administrator()->state(['email' => 'admin@example.test'])->create())
            ->postJson('/api/admin/broadcast', [
                'subject' => 'x', 'body' => 'y', 'audience' => 'everyone',
            ])
            ->assertOk()
            // The real account and the administrator; not the server account,
            // the empty address or the malformed one.
            ->assertJsonPath('data.recipients', 2);
    }

    #[Test]
    public function the_body_cannot_carry_a_script_into_every_inbox(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Hi',
                'body' => "Hello<script>alert(1)</script>\n\n**Everyone**.",
                'audience' => 'address',
                'address' => 'one@example.test',
                'confirm_recipients' => 1,
            ])->assertOk();

        Mail::assertQueued(BroadcastMessage::class, function (BroadcastMessage $mail): bool {
            $rendered = $mail->render();

            $this->assertStringNotContainsString('<script', $rendered);
            $this->assertStringContainsString('<strong>Everyone</strong>', $rendered);

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Terms of service
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_terms_are_served_from_the_cms(): void
    {
        config()->set('panel.terms_of_service_path', 'terms');

        DB::connection($this->loginConnection())->table('cp_cmspages')->insert([
            'path' => 'terms', 'title' => 'Terms of Service',
            'body' => "## Rules\n\nBe **decent**.", 'modified' => now(),
        ]);

        $this->getJson('/api/terms')
            ->assertOk()
            ->assertJsonPath('data.title', 'Terms of Service')
            ->assertJsonPath('data.body_html', "<h2>Rules</h2>\n<p>Be <strong>decent</strong>.</p>\n");
    }

    #[Test]
    public function a_server_with_no_terms_says_so(): void
    {
        // Rather than an empty page that looks broken.
        $this->getJson('/api/terms')
            ->assertNotFound()
            ->assertJsonPath('message', 'This server has not published terms of service.');
    }

    #[Test]
    public function the_terms_are_public(): void
    {
        DB::connection($this->loginConnection())->table('cp_cmspages')->insert([
            'path' => 'terms', 'title' => 'Terms', 'body' => 'x', 'modified' => now(),
        ]);

        $this->getJson('/api/terms')->assertOk();
        $this->assertGuest();
    }
}
