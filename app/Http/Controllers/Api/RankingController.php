<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Ranking\RankingService;
use App\Services\Rathena\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public character ladders.
 */
final class RankingController
{
    public function __construct(
        private readonly RankingService $rankings,
        private readonly ReferenceData $reference,
    ) {}

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
     * The alchemist and blacksmith fame ladders.
     *
     * One action per branch in the legacy panel; one route with the branch in
     * the path here, because the two differ only by which class ids count.
     */
    public function byFame(Request $request, string $branch): JsonResponse
    {
        return $this->respond(
            $this->rankings->byFame($branch, $this->limit($request)),
            fn (object $row): array => ['fame' => (int) $row->fame],
        );
    }

    public function byDeaths(Request $request): JsonResponse
    {
        return $this->respond(
            $this->rankings->byDeaths($this->limit($request), $this->jobClass($request)),
            fn (object $row): array => ['deaths' => (int) $row->death_count],
        );
    }

    /**
     * The homunculus ladder, which ranks homunculi rather than characters.
     */
    public function byHomunculus(Request $request): JsonResponse
    {
        $rows = $this->rankings->byHomunculus($this->limit($request));

        return response()->json([
            'data' => $rows->values()->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'homunculus' => [
                    'id' => (int) $row->homun_id,
                    'name' => $row->homunculus_name,
                    'class_id' => (int) $row->class,
                    'class_name' => $this->reference->homunculusName((int) $row->class),
                    'level' => (int) $row->level,
                    'experience' => (int) $row->exp,
                    'intimacy' => (int) $row->intimacy,
                ],
                'owner' => [
                    'id' => (int) $row->owner_char_id,
                    'name' => $row->owner_name,
                ],
                'guild' => (int) $row->guild_id > 0 ? [
                    'id' => (int) $row->guild_id,
                    'name' => $row->guild_name,
                    'emblem_id' => (int) $row->guild_emblem_id,
                ] : null,
            ])->all(),
        ]);
    }

    /**
     * The guild ladder, which ranks guilds rather than characters.
     */
    public function byGuild(Request $request): JsonResponse
    {
        $rows = $this->rankings->byGuild($this->limit($request));

        return response()->json([
            'data' => $rows->values()->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'guild' => [
                    'id' => (int) $row->guild_id,
                    'name' => $row->name,
                    'level' => (int) $row->guild_lv,
                    'emblem_id' => (int) $row->emblem_id,
                    'average_level' => (int) $row->average_lv,
                    'experience' => (int) $row->guild_exp,
                    'members' => (int) $row->member_count,
                    'max_members' => (int) $row->max_member,
                    'castles' => (int) $row->castle_count,
                ],
            ])->all(),
        ]);
    }

    /**
     * The MVP ladder. `monster` narrows it to one boss.
     */
    public function byMvp(Request $request): JsonResponse
    {
        $request->validate(['monster' => ['nullable', 'integer', 'min:0']]);

        $monsterId = $request->filled('monster') ? $request->integer('monster') : null;

        $rows = $this->rankings->byMvp($this->limit($request), $monsterId);

        return response()->json([
            'data' => $rows->values()->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'character' => [
                    'id' => $row->char_id,
                    'name' => $row->name,
                    'job_id' => $row->class,
                    'job_name' => $this->reference->jobName($row->class),
                    'base_level' => $row->base_level,
                ],
                'monster' => [
                    'id' => $row->monster_id,
                    'name' => $row->monster_name,
                ],
                'kills' => $row->kill_count,
                'guild' => $row->guild_id > 0 ? [
                    'id' => $row->guild_id,
                    'name' => $row->guild_name,
                    'emblem_id' => (int) $row->guild_emblem_id,
                ] : null,
            ])->all(),
        ]);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function respond(Collection $rows, ?callable $extra = null): JsonResponse
    {
        return response()->json([
            'data' => $rows->values()->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                // Whatever this particular ladder is ordered by: fame for the
                // craft ladders, deaths for the death one.
                ...($extra === null ? [] : $extra($row)),
                'character' => [
                    'id' => (int) $row->char_id,
                    'name' => $row->name,
                    'job_id' => (int) $row->class,
                    'job_name' => $this->reference->jobName((int) $row->class),
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
