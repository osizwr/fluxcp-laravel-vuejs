<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Server\ServerStatisticsService;
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
            'data' => $this->statistics->summary(),
            'meta' => [
                'cache_seconds' => (int) config('panel.statistics.cache_seconds', 300),
            ],
        ]);
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
