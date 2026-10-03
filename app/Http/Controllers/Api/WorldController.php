<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;

/**
 * The state of the world: castle ownership and the siege schedule.
 *
 * Ports modules/castle/index.php and modules/woe/index.php.
 */
final class WorldController
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Who holds which castle.
     *
     * Only the castles the operator configured are listed. Removing one from
     * `rathena_reference.castles` takes it off this page and out of the guild
     * ladder's castle count, which is how a server that does not run the
     * novice castles excludes them.
     */
    public function castles(): JsonResponse
    {
        $names = (array) config('rathena_reference.castles', []);

        if ($names === []) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $server = $this->servers->currentCharMapServer();

        $rows = $this->connections->connection($server->connectionName())
            ->table('guild_castle as castles')
            ->select([
                'castles.castle_id',
                'castles.guild_id',
                'castles.economy',
                'castles.defense',
                'guild.name as guild_name',
                'guild.emblem_id',
            ])
            ->leftJoin('guild', 'guild.guild_id', '=', 'castles.guild_id')
            ->whereIn('castles.castle_id', array_keys($names))
            ->orderBy('castles.castle_id')
            ->get()
            ->keyBy('castle_id');

        $castles = [];

        foreach ($names as $id => $name) {
            $row = $rows[$id] ?? null;
            $guildId = (int) ($row->guild_id ?? 0);

            $castles[] = [
                'id' => (int) $id,
                'name' => (string) $name,
                'economy' => (int) ($row->economy ?? 0),
                'defense' => (int) ($row->defense ?? 0),
                /*
                 * Null rather than an empty guild. A castle with no row in
                 * guild_castle, and one whose guild_id is 0, are both
                 * unowned -- the table is only populated once a castle has
                 * been taken.
                 */
                'owner' => $guildId > 0 ? [
                    'id' => $guildId,
                    'name' => (string) ($row->guild_name ?? ''),
                    'emblem_id' => (int) ($row->emblem_id ?? 0),
                    'emblem_url' => (int) ($row->emblem_id ?? 0) > 0
                        ? "/api/guilds/{$guildId}/emblem"
                        : null,
                ] : null,
            ];
        }

        return response()->json([
            'data' => $castles,
            'meta' => [
                'total' => count($castles),
                'held' => count(array_filter($castles, fn (array $c): bool => $c['owner'] !== null)),
            ],
        ]);
    }

    /**
     * The War of Emperium schedule.
     *
     * Entirely configuration: the windows come from each char/map server's
     * own settings, so a group running two worlds with different siege times
     * reports both rather than one global schedule.
     *
     * Times are reported in the world's own timezone *and* as the next
     * absolute start, because a schedule that says "Saturday 20:00" without
     * saying whose 20:00 is the single most common source of players turning
     * up an hour out.
     */
    public function siegeSchedule(): JsonResponse
    {
        $worlds = [];

        foreach ($this->servers->current()->charMapServers as $server) {
            $windows = [];

            foreach ($server->woeWindows as $window) {
                $windows[] = [
                    'starts' => [
                        'day' => $window->startDayName(),
                        'time' => $window->startTime,
                    ],
                    'ends' => [
                        'day' => $window->endDayName(),
                        'time' => $window->endTime,
                    ],
                    'duration_minutes' => $window->durationInMinutes(),
                ];
            }

            $worlds[] = [
                'key' => $server->key,
                'name' => $server->name,
                'timezone' => $server->timezone()->getName(),
                'server_time' => $server->serverTime()->toIso8601String(),
                'active' => $server->isWoeActive(),
                'next_start' => $server->nextWoeStart()?->toIso8601String(),
                'windows' => $windows,
            ];
        }

        return response()->json(['data' => $worlds]);
    }
}
