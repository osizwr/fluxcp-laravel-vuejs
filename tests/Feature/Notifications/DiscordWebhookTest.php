<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendDiscordNotification;
use App\Models\Account;
use App\Services\Notifications\DiscordWebhook;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Discord notifications.
 *
 * Covers lib/functions/discordwebhook.php and the five places that called it,
 * which were the last of the legacy's own libraries left unported.
 */
final class DiscordWebhookTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const HOOK = 'https://discord.com/api/webhooks/1/abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.discord.enabled' => true,
            'panel.discord.webhook_url' => self::HOOK,
            'panel.discord.queue' => false,
        ]);
    }

    /**
     * Faked per test rather than in setUp(). A second Http::fake() does not
     * reliably replace an earlier one, so a test that needs a failure response
     * has to be the first to fake -- the same trap the mail tests hit.
     */
    private function fakeAccepted(): void
    {
        Http::fake([self::HOOK => Http::response('', 204)]);
    }

    private function discord(): DiscordWebhook
    {
        return $this->app->make(DiscordWebhook::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_notification_is_posted_to_the_webhook(): void
    {
        $this->fakeAccepted();

        $this->assertTrue($this->discord()->notify('registration', 'Hello.'));

        Http::assertSent(function ($request): bool {
            return $request->url() === self::HOOK
                && $request->method() === 'POST'
                && $request['content'] === 'Hello.';
        });
    }

    /**
     * These messages quote names and free text that players chose, so an
     * account called `@everyone` would otherwise ping the whole server. The
     * payload disables mentions rather than the text being filtered, so it
     * holds for content this code has never seen.
     */
    #[Test]
    public function mentions_are_disabled_in_the_payload(): void
    {
        $this->fakeAccepted();

        $this->discord()->notify('registration', 'New registration: @everyone');

        Http::assertSent(fn ($request): bool => $request['allowed_mentions'] === ['parse' => []]);
    }

    #[Test]
    public function nothing_is_sent_when_the_feature_is_off(): void
    {
        $this->fakeAccepted();

        config(['panel.discord.enabled' => false]);

        $this->assertFalse($this->discord()->notify('registration', 'Hello.'));

        Http::assertNothingSent();
    }

    #[Test]
    public function nothing_is_sent_when_an_event_is_turned_off(): void
    {
        $this->fakeAccepted();

        config(['panel.discord.events.registration' => false]);

        $this->assertFalse($this->discord()->notify('registration', 'Hello.'));

        Http::assertNothingSent();
    }

    /**
     * A webhook URL carries a token that authorises posting to the channel, so
     * it is not sent over a connection that would expose it.
     */
    #[Test]
    public function an_http_webhook_url_is_refused(): void
    {
        $this->fakeAccepted();

        config(['panel.discord.webhook_url' => 'http://discord.com/api/webhooks/1/abcdef']);

        $this->assertFalse($this->discord()->notify('registration', 'Hello.'));

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unknown_event_name_is_not_sent(): void
    {
        $this->fakeAccepted();

        $this->assertFalse($this->discord()->notify('not_an_event', 'Hello.'));

        Http::assertNothingSent();
    }

    #[Test]
    public function an_overlong_message_is_truncated_to_what_discord_accepts(): void
    {
        $this->fakeAccepted();

        $this->discord()->notify('registration', Str::repeat('a', 5_000));

        Http::assertSent(fn ($request): bool => strlen((string) $request['content']) <= 2_000);
    }

    /*
    |--------------------------------------------------------------------------
    | When Discord misbehaves
    |--------------------------------------------------------------------------
    */

    /**
     * The legacy discarded the result, so an operator could not tell a quiet
     * channel from a broken webhook.
     */
    #[Test]
    public function a_rejected_post_is_logged_and_reported_as_a_failure(): void
    {
        Http::fake([self::HOOK => Http::response('', 404)]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Discord')
                    && $context['event'] === 'registration'
                    // The URL is never logged: it contains its own token.
                    && ! str_contains(json_encode($context), 'webhooks/1/abcdef');
            });

        $this->assertFalse($this->discord()->notify('registration', 'Hello.'));
    }

    #[Test]
    public function a_connection_failure_does_not_throw(): void
    {
        Http::fake([self::HOOK => Http::failedConnection()]);

        Log::shouldReceive('warning')->once();

        $this->assertFalse($this->discord()->notify('registration', 'Hello.'));
    }

    /*
    |--------------------------------------------------------------------------
    | The queue
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function delivery_can_be_moved_off_the_request(): void
    {
        $this->fakeAccepted();

        config(['panel.discord.queue' => true]);

        Queue::fake();

        $this->assertTrue($this->discord()->notify('registration', 'Hello.'));

        Queue::assertPushed(SendDiscordNotification::class);
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | The five events the legacy announced
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_registration_is_announced(): void
    {
        $this->fakeAccepted();

        config(['panel.registration.enabled' => true]);

        $this->postJson('/api/auth/register', [
            'username' => 'newplayer',
            'password' => 'A-strong-passphrase-1',
            'password_confirmation' => 'A-strong-passphrase-1',
            'email' => 'newplayer@example.com',
            'gender' => 'M',
        ])->assertCreated();

        Http::assertSent(fn ($request): bool => str_contains((string) $request['content'], 'newplayer'));
    }

    #[Test]
    public function a_new_ticket_is_announced(): void
    {
        $this->fakeAccepted();

        $category = (int) DB::connection($this->loginConnectionName())
            ->table('cp_servicedeskcat')
            ->insertGetId(['name' => 'Bug report', 'display' => 1]);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/support/tickets', [
                'category' => $category,
                'subject' => 'Stuck in a wall',
                'text' => 'Please help.',
            ])
            ->assertStatus(201);

        Http::assertSent(fn ($request): bool => str_contains((string) $request['content'], 'Stuck in a wall'));
    }

    #[Test]
    public function a_web_command_is_announced(): void
    {
        $this->fakeAccepted();

        config([
            'panel.characters.web_commands.enabled' => true,
            'panel.characters.web_commands.allowed' => ['@refresh'],
        ]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->postJson('/api/account/commands', ['command' => '@refresh'])
            ->assertStatus(201);

        Http::assertSent(fn ($request): bool => str_contains((string) $request['content'], '@refresh'));
    }

    #[Test]
    public function a_mass_mailing_is_announced(): void
    {
        $this->fakeAccepted();
        Mail::fake();

        Account::factory()->count(2)->create();
        $admin = Account::factory()->administrator()->create();

        /*
         * Two requests: the first reports the count, the second confirms it.
         * Only the second sends, so only the second should announce.
         */
        $count = $this->actingAs($admin)
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Maintenance', 'body' => 'Friday.', 'audience' => 'everyone',
            ])->json('data.recipients');

        Http::assertNothingSent();

        $this->actingAs($admin)
            ->postJson('/api/admin/broadcast', [
                'subject' => 'Maintenance',
                'body' => 'Friday.',
                'audience' => 'everyone',
                'confirm_recipients' => $count,
            ])
            ->assertOk();

        Http::assertSent(fn ($request): bool => str_contains((string) $request['content'], 'Maintenance'));
    }

    /*
    |--------------------------------------------------------------------------
    | Unhandled exceptions
    |--------------------------------------------------------------------------
    */

    /**
     * Off unless an operator turns it on, which is the opposite of the legacy
     * default: an exception message is where a database credential or a file
     * path ends up, and a chat channel is read by more people than a log is.
     */
    #[Test]
    public function an_exception_is_not_announced_by_default(): void
    {
        $this->assertFalse(config('panel.discord.events.exception'));
    }

    #[Test]
    public function an_exception_is_announced_when_the_operator_asks_for_it(): void
    {
        $this->fakeAccepted();

        config(['panel.discord.events.exception' => true]);

        $this->discord()->notify('exception', 'Unhandled RuntimeException: it broke');

        Http::assertSent(fn ($request): bool => str_contains((string) $request['content'], 'it broke'));
    }

    /**
     * A 404 is not an error worth a chat message, and announcing every one
     * would make the channel useless on the first crawler.
     */
    #[Test]
    public function an_http_exception_is_not_announced(): void
    {
        $this->fakeAccepted();

        config(['panel.discord.events.exception' => true]);

        $this->getJson('/api/items/99999999')->assertNotFound();

        Http::assertNothingSent();
    }

    private function loginConnectionName(): string
    {
        return $this->app->make(ServerRegistry::class)
            ->current()
            ->loginConnection();
    }
}
