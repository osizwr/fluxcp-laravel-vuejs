<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Server\GroupStatus;
use App\Services\Server\ServerStatusService;
use Illuminate\Http\JsonResponse;

/**
 * Live status of the configured game servers.
 *
 * The figures are measured, not estimated: reachability from an actual TCP
 * connection to each process, and the player count from rAthena's own
 * `char.online` column.
 */
final class ServerStatusController
{
    public function __construct(private readonly ServerStatusService $status) {}

    public function index(): JsonResponse
    {
        $groups = $this->status->all();

        return response()->json([
            'data' => $groups->map(fn (GroupStatus $group): array => $group->toArray())->all(),
            'meta' => [
                'players_online' => $groups->sum(
                    fn (GroupStatus $group): int => $group->totalPlayersOnline()
                ),
                'measured_at' => now()->toIso8601String(),
                /*
                 * Told to the client so it knows there is no point polling
                 * faster than the figures can change.
                 */
                'cache_seconds' => (int) config('panel.server_status.cache_seconds', 30),
            ],
        ]);
    }
}
