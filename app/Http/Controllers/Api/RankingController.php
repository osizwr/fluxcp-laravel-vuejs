<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Ranking\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public character ladders.
 */
final class RankingController
{
    public function __construct(private readonly RankingService $rankings) {}

    public function byLevel(Request $request): JsonResponse
    {
        return $this->respond(
            $this->rankings->byLevel($this->limit($request), $this->jobClass($request)),
        );
    }

    public function byZeny(Request $request): JsonResponse
    {
        return $this->respond(
            $this->rankings->byZeny($this->limit($request), $this->jobClass($request)),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function respond(\Illuminate\Support\Collection $rows): JsonResponse
    {
        return response()->json([
            'data' => $rows->values()->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'character' => [
                    'id' => (int) $row->char_id,
                    'name' => $row->name,
                    'job_id' => (int) $row->class,
                    'base_level' => (int) $row->base_level,
                    'job_level' => (int) $row->job_level,
                    'zeny' => (int) $row->zeny,
                ],
                'guild' => $row->guild_id > 0 ? [
                    'id' => (int) $row->guild_id,
                    'name' => $row->guild_name,
                    'emblem_id' => (int) $row->guild_emblem_id,
                ] : null,
            ])->all(),
        ]);
    }

    /**
     * Clamp the requested size so a client cannot ask for the whole table.
     */
    private function limit(Request $request): int
    {
        $default = (int) config('panel.rankings.limit', 100);
        $max = (int) config('panel.pagination.max_per_page', 100);

        $requested = (int) $request->integer('limit', $default);

        return max(1, min($requested, $max));
    }

    private function jobClass(Request $request): ?int
    {
        return $request->has('job_class')
            ? $request->integer('job_class')
            : null;
    }
}
