<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\DiscordWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A Discord notification, delivered off the request.
 *
 * Only used when `panel.discord.queue` is on. Without it DiscordWebhook posts
 * inline, which is what the legacy did and is the right default on a server
 * with no queue worker running.
 */
final class SendDiscordNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, backing off, because the usual reason this fails is a
     * chat server having a bad minute.
     */
    public int $tries = 3;

    public function __construct(
        private readonly string $event,
        private readonly string $message,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(DiscordWebhook $discord): void
    {
        /*
         * send() rather than notify(): the decision to send was taken when the
         * job was dispatched, and asking again here would drop the
         * notification if an operator happened to turn the event off between
         * then and the worker picking it up.
         */
        $discord->send($this->event, $this->message);
    }
}
