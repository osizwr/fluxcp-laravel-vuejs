<?php

declare(strict_types=1);

namespace App\Services\Server;

use Illuminate\Support\Collection;

/**
 * The status of a whole server group.
 */
final readonly class GroupStatus
{
    /**
     * @param  Collection<int, CharMapServerStatus>  $servers
     */
    public function __construct(
        public string $key,
        public string $name,
        public bool $loginServerUp,
        public Collection $servers,
    ) {}

    public function totalPlayersOnline(): int
    {
        return (int) $this->servers->sum(
            fn (CharMapServerStatus $status): int => $status->playersOnline
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'login_server_up' => $this->loginServerUp,
            'players_online' => $this->totalPlayersOnline(),
            'servers' => $this->servers
                ->map(fn (CharMapServerStatus $status): array => $status->toArray())
                ->values()
                ->all(),
        ];
    }
}
