<?php

declare(strict_types=1);

namespace App\Events;

use App\Services\Server\GroupStatus;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Server status changed.
 *
 * Broadcast on a public channel, which is only safe because every field here is
 * already public on the status page: whether each process answers a TCP
 * connection, and how many characters are flagged online. Nothing about an
 * individual account or character goes anywhere near it.
 *
 * Anything carrying account or character detail must use a private or presence
 * channel instead, because a public channel is readable by anyone who knows its
 * name. See docs/MIGRATION_DECISIONS.md (D14).
 */
final class ServerStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  Collection<int, GroupStatus>  $groups
     */
    public function __construct(public readonly Collection $groups) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('server-status')];
    }

    /**
     * Named explicitly so the client listens for a stable string rather than a
     * class name that would change if this class were ever moved.
     */
    public function broadcastAs(): string
    {
        return 'server.status.updated';
    }

    /**
     * The payload, shaped identically to the REST response.
     *
     * Keeping the two the same means the client applies a broadcast and a poll
     * through one code path, so the fallback cannot drift from the live path.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'data' => $this->groups->map(fn (GroupStatus $group): array => $group->toArray())->all(),
            'meta' => [
                'players_online' => $this->groups->sum(
                    fn (GroupStatus $group): int => $group->totalPlayersOnline()
                ),
                'measured_at' => now()->toIso8601String(),
                'cache_seconds' => (int) config('panel.server_status.cache_seconds', 30),
            ],
        ];
    }
}
