<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Guild;
use App\Services\Rathena\GuildEmblemService;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guilds: the directory, one guild's page, its emblem and its roster export.
 *
 * Ports modules/guild/index.php, view.php, emblem.php and export.php.
 */
final class GuildController
{
    public function __construct(
        private readonly GuildEmblemService $emblems,
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * The guild directory.
     *
     * @throws ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['name' => ['nullable', 'string', 'max:24']]);

        $connection = $this->connections->connection($this->charMapConnection());

        $members = $connection->table('char')
            ->selectRaw('count(char_id)')
            ->whereColumn('char.guild_id', 'g.guild_id');

        $query = $connection->table('guild as g')
            ->select([
                'g.guild_id', 'g.name', 'g.guild_lv', 'g.max_member',
                'g.average_lv', 'g.emblem_id', 'g.master', 'g.exp',
            ])
            ->selectSub($members, 'member_count');

        if (($name = trim((string) $request->string('name'))) !== '') {
            // Wildcards escaped so a search for "%" does not match every guild.
            $query->where('g.name', 'like', '%'.addcslashes($name, '%_\\').'%');
        }

        $list = new ListQuery(
            sortable: [
                'name' => 'g.name',
                'level' => 'g.guild_lv',
                'members' => 'member_count',
                'average_level' => 'g.average_lv',
            ],
            defaultSort: 'level',
        );

        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map($this->asSummary(...), $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * One guild, with its roster, allies and enemies.
     */
    public function show(Request $request, int $guild): JsonResponse
    {
        $connection = $this->connections->connection($this->charMapConnection());

        $row = $connection->table('guild as g')
            ->select([
                'g.guild_id', 'g.name', 'g.guild_lv', 'g.max_member', 'g.average_lv',
                'g.emblem_id', 'g.master', 'g.exp', 'g.next_exp', 'g.skill_point', 'g.mes1', 'g.mes2',
            ])
            ->where('g.guild_id', $guild)
            ->first();

        abort_if($row === null, 404, 'No such guild.');

        return response()->json([
            'data' => [
                ...$this->asSummary($row),
                'experience' => (int) ($row->exp ?? 0),
                'next_experience' => (int) ($row->next_exp ?? 0),
                'skill_points' => (int) ($row->skill_point ?? 0),
                'notice' => [
                    'title' => (string) ($row->mes1 ?? ''),
                    'body' => (string) ($row->mes2 ?? ''),
                ],
                'members' => $this->members($guild),
                'allies' => $this->relations($guild, opposition: 0),
                'enemies' => $this->relations($guild, opposition: 1),
                'castles' => $this->castles($guild),
            ],
        ]);
    }

    /**
     * A guild's emblem as a PNG.
     *
     * 404 rather than a placeholder image when there is none: the client knows
     * better than this endpoint whether to draw a fallback, and a placeholder
     * served with a 200 cannot be told apart from a real emblem by a cache.
     */
    public function emblem(int $guild): Response
    {
        abort_unless(config('panel.guilds.emblems') === true, 404);

        $png = $this->emblems->png($guild);

        abort_if($png === null, 404, 'This guild has no emblem.');

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // Emblems change rarely and are requested once per row of a
            // listing, so a browser cache is worth more here than freshness.
            'Cache-Control' => 'public, max-age=600',
        ]);
    }

    /**
     * The roster as CSV.
     *
     * Streamed rather than assembled in memory, and capped, because a large
     * guild's roster requested repeatedly is otherwise a cheap way to make the
     * server do a lot of work.
     */
    public function export(int $guild): StreamedResponse
    {
        $members = $this->members($guild, (int) config('panel.guilds.export_limit', 500));

        abort_if($members === [], 404, 'No such guild, or it has no members.');

        $filename = sprintf('guild-%d-members.csv', $guild);

        return response()->streamDownload(function () use ($members): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Character', 'Level', 'Job level', 'Class', 'Position', 'Last login']);

            foreach ($members as $member) {
                fputcsv($handle, [
                    $member['name'],
                    $member['base_level'],
                    $member['job_level'],
                    $member['job_id'],
                    $member['position'],
                    $member['last_login'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /*
    |--------------------------------------------------------------------------
    | Pieces
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function asSummary(object $row): array
    {
        return [
            'id' => (int) $row->guild_id,
            'name' => (string) $row->name,
            'level' => (int) ($row->guild_lv ?? 0),
            'average_level' => (int) ($row->average_lv ?? 0),
            'members' => (int) ($row->member_count ?? 0),
            'max_members' => (int) ($row->max_member ?? 0),
            'emblem_id' => (int) ($row->emblem_id ?? 0),
            'master' => (string) ($row->master ?? ''),
            /*
             * Only offered when the guild has an emblem id, so the client is
             * not asking for images that are known not to exist.
             */
            'emblem_url' => (int) ($row->emblem_id ?? 0) > 0
                ? "/api/guilds/{$row->guild_id}/emblem"
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(int $guild, ?int $limit = null): array
    {
        $rows = $this->connections->connection($this->charMapConnection())
            ->table('char as ch')
            ->select([
                'ch.char_id', 'ch.name', 'ch.class', 'ch.base_level', 'ch.job_level',
                'roster.position',
            ])
            ->leftJoin('guild_member as roster', 'roster.char_id', '=', 'ch.char_id')
            ->where('ch.guild_id', $guild)
            ->where(fn ($q) => $q->where('ch.delete_date', 0)->orWhereNull('ch.delete_date'))
            ->orderBy('roster.position')
            ->orderBy('ch.name')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->char_id,
            'name' => (string) $row->name,
            'job_id' => (int) $row->class,
            'base_level' => (int) $row->base_level,
            'job_level' => (int) $row->job_level,
            'position' => (int) ($row->position ?? 0),
        ])->all();
    }

    /**
     * Allies or enemies.
     *
     * @return list<array<string, mixed>>
     */
    private function relations(int $guild, int $opposition): array
    {
        return $this->connections->connection($this->charMapConnection())
            ->table('guild_alliance as a')
            ->select(['a.alliance_id', 'a.name'])
            ->where('a.guild_id', $guild)
            ->where('a.opposition', $opposition)
            ->orderBy('a.name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->alliance_id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function castles(int $guild): array
    {
        return $this->connections->connection($this->charMapConnection())
            ->table('guild_castle')
            ->where('guild_id', $guild)
            ->orderBy('castle_id')
            ->pluck('castle_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function charMapConnection(): string
    {
        return $this->servers->currentCharMapServer()->connectionName();
    }
}
