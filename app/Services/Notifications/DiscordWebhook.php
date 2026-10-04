<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\SendDiscordNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator notifications to a Discord channel.
 *
 * Ports `lib/functions/discordwebhook.php` and the five places that called it:
 * a registration, a new support ticket, a web command, a mass mailing and an
 * unhandled exception.
 *
 * ---------------------------------------------------------------------------
 * What the legacy did, and what is done instead
 * ---------------------------------------------------------------------------
 *
 * `sendtodiscord()` was a bare cURL POST with no timeout, run inline on the
 * request that triggered it and its result discarded. Three consequences:
 *
 *   - a slow Discord made registration slow. With no timeout at all, an
 *     unresponsive endpoint held the request until PHP's own limit.
 *   - a failure was invisible, so an operator relying on these notifications
 *     could not tell a quiet channel from a broken webhook.
 *   - the message was built by string concatenation from whatever triggered
 *     it, including the account name somebody had just chosen. An account
 *     called `@everyone` therefore pinged the whole server on registration.
 *
 * So: a timeout, a logged failure, and `allowed_mentions` set to parse nothing
 * — which disables mentions in the payload rather than trying to spot them in
 * the text. Being structural, it holds for message content this class has
 * never seen.
 *
 * Delivery can go through the queue, off by default for the same reason as the
 * mail queue: a queued job on a server with no worker running is a job that
 * never happens, and an operator who has not set up a worker is better served
 * by a notification that costs a moment than by one that never arrives.
 */
final readonly class DiscordWebhook
{
    /**
     * The events the legacy could announce, as its option names implied them.
     *
     * Declared rather than free-form so a caller cannot invent an event name
     * that no configuration key answers to, which would be a notification
     * silently never sent.
     */
    public const EVENTS = [
        'registration',
        'ticket',
        'web_command',
        'broadcast',
        'exception',
    ];

    /** Discord rejects a message body longer than this. */
    private const MAX_CONTENT = 2_000;

    /**
     * Whether an event should be announced at all.
     */
    public function wants(string $event): bool
    {
        return config('panel.discord.enabled') === true
            && $this->url() !== null
            && in_array($event, self::EVENTS, true)
            && config("panel.discord.events.{$event}") === true;
    }

    /**
     * Announce something, if the operator asked for it.
     *
     * Never throws. A notification is a convenience, and failing the action
     * that triggered it because a chat server was unreachable would be the
     * wrong trade -- a registration must not fail because Discord is down.
     */
    public function notify(string $event, string $message): bool
    {
        if (! $this->wants($event)) {
            return false;
        }

        if (config('panel.discord.queue') === true) {
            SendDiscordNotification::dispatch($event, $message);

            return true;
        }

        return $this->send($event, $message);
    }

    /**
     * Post to the webhook.
     *
     * Called directly by the queued job, which has already decided to send.
     */
    public function send(string $event, string $message): bool
    {
        $url = $this->url();

        if ($url === null) {
            return false;
        }

        try {
            $response = Http::timeout((int) config('panel.discord.timeout_seconds', 5))
                ->connectTimeout((int) config('panel.discord.timeout_seconds', 5))
                ->asJson()
                ->post($url, [
                    'content' => Str::limit($message, self::MAX_CONTENT - 3),
                    /*
                     * Nothing in a notification is allowed to mention anybody.
                     * These messages quote names and text that players chose,
                     * and `@everyone` in an account name would otherwise ping
                     * the whole server.
                     */
                    'allowed_mentions' => ['parse' => []],
                ]);

            if ($response->successful()) {
                return true;
            }

            $this->logFailure($event, 'Discord returned HTTP '.$response->status().'.');

            return false;
        } catch (Throwable $exception) {
            $this->logFailure($event, $exception->getMessage());

            return false;
        }
    }

    /**
     * Logged rather than thrown, so an operator can tell a quiet channel from
     * a broken webhook. The legacy discarded the result and left no trace.
     *
     * The URL is never logged: a Discord webhook URL contains its own token,
     * so anybody who can read the log could post to the channel.
     */
    private function logFailure(string $event, string $reason): void
    {
        Log::warning('A Discord notification could not be delivered.', [
            'event' => $event,
            'reason' => $reason,
        ]);
    }

    private function url(): ?string
    {
        $url = trim((string) config('panel.discord.webhook_url', ''));

        if ($url === '') {
            return null;
        }

        /*
         * https only. A webhook URL carries a token that authorises posting to
         * the channel, and sending it over http would put that token on the
         * wire in clear.
         */
        return str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}
