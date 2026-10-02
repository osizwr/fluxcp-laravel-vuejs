<?php

declare(strict_types=1);

namespace App\Services\Server;

/**
 * The status of one char/map pair, and of the login server it sits behind.
 *
 * The login server's state is repeated on each pair rather than held once per
 * group because that is how the status is read: a visitor looks at one world
 * and wants to know whether they can get into it, which needs all three
 * processes.
 */
final readonly class CharMapServerStatus
{
    public function __construct(
        public string $key,
        public string $name,
        public bool $loginServerUp,
        public bool $charServerUp,
        public bool $mapServerUp,
        public int $playersOnline,
        public ?int $playersPeak,
        public bool $woeActive,
    ) {}

    /**
     * Whether a player could actually log in and play right now. All three
     * processes are needed: the login server to authenticate, the char server
     * to pick a character, the map server to enter the world.
     */
    public function isPlayable(): bool
    {
        return $this->loginServerUp && $this->charServerUp && $this->mapServerUp;
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
            'char_server_up' => $this->charServerUp,
            'map_server_up' => $this->mapServerUp,
            'playable' => $this->isPlayable(),
            'players_online' => $this->playersOnline,
            'players_peak' => $this->playersPeak,
            'woe_active' => $this->woeActive,
        ];
    }
}
