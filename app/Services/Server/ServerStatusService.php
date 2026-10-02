<?php

declare(strict_types=1);

namespace App\Services\Server;

use App\Contracts\ProbesServerReachability;
use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Assembles the status of every configured server.
 *
 * The figures are real measurements, not estimates: reachability comes from an
 * actual TCP connection to each process, and the player count from rAthena's
 * own `char.online` column, which the map server maintains.
 */
final readonly class ServerStatusService
{
    public function __construct(
        private ServerRegistry $servers,
        private ProbesServerReachability $probe,
        private ConnectionResolverInterface $connections,
    ) {}

    /**
     * @return Collection<int, GroupStatus>
     */
    public function all(): Collection
    {
        return $this->servers->all()
            ->map(fn (ServerGroup $group): GroupStatus => $this->forGroup($group))
            ->values();
    }

    public function forGroup(ServerGroup $group): GroupStatus
    {
        // Probed once per group, not once per pair: every pair in a group sits
        // behind the same login server.
        $loginServerUp = $this->probe->isReachable($group->loginServer->endpoint());

        $servers = $group->charMapServers
            ->map(fn (CharMapServer $pair): CharMapServerStatus => new CharMapServerStatus(
                key: $pair->key,
                name: $pair->name,
                loginServerUp: $loginServerUp,
                charServerUp: $this->probe->isReachable($pair->charServer),
                mapServerUp: $this->probe->isReachable($pair->mapServer),
                playersOnline: $this->playersOnline($pair),
                playersPeak: $this->playersPeak($pair),
                woeActive: $pair->isWoeActive(),
            ))
            ->values();

        return new GroupStatus(
            key: $group->key,
            name: $group->name,
            loginServerUp: $loginServerUp,
            servers: $servers,
        );
    }

    /**
     * Characters currently in the world.
     *
     * rAthena's map server sets `char.online` and clears it on disconnect. It
     * can be left stale by a crash, which is a property of the emulator rather
     * than something the panel should second-guess, so the raw count is
     * reported.
     */
    public function playersOnline(CharMapServer $pair): int
    {
        return $this->guard(
            fn (): int => (int) $this->connections
                ->connection($pair->connectionName())
                ->table('char')
                ->where('online', '>', 0)
                ->count(),
            0,
        );
    }

    /**
     * The recorded peak concurrent player count, or null when peak display is
     * switched off or nothing has been recorded.
     *
     * Read from the pair's own database. The legacy implementation read it
     * through the session's currently preferred server while iterating over
     * every other one, so with more than one char/map pair it reported the
     * wrong figure.
     */
    public function playersPeak(CharMapServer $pair): ?int
    {
        if (config('panel.server_status.show_peak') !== true) {
            return null;
        }

        return $this->guard(
            function () use ($pair): ?int {
                $peak = $this->connections
                    ->connection($pair->connectionName())
                    ->table('cp_onlinepeak')
                    ->max('users');

                return $peak === null ? null : (int) $peak;
            },
            null,
        );
    }

    /**
     * Run a query, falling back to $default if the database cannot be reached.
     *
     * The status page is the page people load precisely when something looks
     * broken, so it must not itself fail when a server's database is down. A
     * group whose database is unreachable reports zero players alongside its
     * processes showing as down, which is the truthful reading.
     *
     * @template T
     *
     * @param  callable(): T  $query
     * @param  T  $default
     * @return T
     */
    private function guard(callable $query, mixed $default): mixed
    {
        try {
            return $query();
        } catch (Throwable) {
            return $default;
        }
    }
}
