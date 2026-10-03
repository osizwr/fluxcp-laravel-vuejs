<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Server\ServerStatisticsService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregate figures about the server.
 *
 * Every value is a real query against rAthena's tables. Server uptime is
 * absent because the emulator records no start time the panel can read, and a
 * figure that had to be invented does not belong in a statistics endpoint.
 */
final class ServerStatisticsController
{
    public function __construct(private readonly ServerStatisticsService $statistics) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->statistics->summary(),
                /*
                 * The configured rates, which complete what the legacy
                 * `server/info` page showed. They are the operator's own
                 * declaration rather than something read from the emulator --
                 * rAthena keeps them in conf files the panel cannot see -- so
                 * a server that has not set them reports the defaults, and the
                 * flag says which it is.
                 */
                'rates' => $this->rates(),
            ],
            'meta' => [
                'cache_seconds' => (int) config('panel.statistics.cache_seconds', 300),
            ],
        ]);
    }

    /**
     * The current world's rates, as percentages.
     *
     * @return array<string, mixed>
     */
    private function rates(): array
    {
        $server = app(ServerRegistry::class)->currentCharMapServer();

        return [
            'declared' => $server->rates !== [],
            'values' => (object) $server->rates,
            'renewal' => $server->renewal,
            'max_character_slots' => $server->maxCharacterSlots,
        ];
    }

    /**
     * How many characters there are of each job class.
     */
    public function classes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->statistics
                ->classDistribution($request->integer('limit', 8))
                ->values()
                ->all(),
        ]);
    }
}
