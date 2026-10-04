<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Guild;
use App\Models\Account;
use App\Services\Rathena\GuildEmblemService;
use App\Services\Rathena\ItemStacks;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
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
        private readonly ItemStacks $stacks,
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
                'g.connect_member', 'g.char_id',
                'g.emblem_id', 'g.master', 'g.exp', 'g.next_exp', 'g.skill_point', 'g.mes1', 'g.mes2',
            ])
            ->where('g.guild_id', $guild)
            ->first();

        abort_if($row === null, 404, 'No such guild.');

        $roster = $this->members($guild);

        return response()->json([
            'data' => [
                ...$this->asSummary($row),
                /*
                 * `members` is the roster here and a count in the listing, so
                 * the count is restated rather than left at the zero
                 * asSummary() produces from a query that does not compute it.
                 */
                'member_count' => count($roster),
                'members_online' => (int) ($row->connect_member ?? 0),
                'experience' => (int) ($row->exp ?? 0),
                'next_experience' => (int) ($row->next_exp ?? 0),
                'skill_points' => (int) ($row->skill_point ?? 0),
                'notice' => [
                    'title' => $this->clean($row->mes1 ?? ''),
                    'body' => $this->clean($row->mes2 ?? ''),
                ],
                'members' => $roster,
                'allies' => $this->relations($guild, opposition: 0),
                'enemies' => $this->relations($guild, opposition: 1),
                'castles' => $this->castles($guild),
                'expulsions' => $this->expulsions($guild),
                'positions' => $this->positions($guild),
                'storage' => $this->storage($request, $guild, (int) ($row->char_id ?? 0)),
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
                'ch.online', 'roster.position', 'roster.exp as devotion',
                'pos.name as position_name', 'pos.mode as position_mode',
                'pos.exp_mode as guild_tax',
            ])
            /*
             * Both columns, not just the character: a guild_member row is
             * keyed on (guild_id, char_id), so joining on char_id alone can
             * match a row left behind by a guild the character has since
             * left.
             */
            ->leftJoin('guild_member as roster', function ($join) {
                $join->on('roster.char_id', '=', 'ch.char_id')
                    ->on('roster.guild_id', '=', 'ch.guild_id');
            })
            ->leftJoin('guild_position as pos', function ($join) {
                $join->on('pos.guild_id', '=', 'ch.guild_id')
                    ->on('pos.position', '=', 'roster.position');
            })
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
            'online' => (int) ($row->online ?? 0) > 0,
            'position' => (int) ($row->position ?? 0),
            'position_name' => $this->clean($row->position_name ?? ''),
            'position_mode' => (int) ($row->position_mode ?? 0),
            // The share of experience this rank pays into the guild, as a
            // percentage. rAthena stores it on the position, not the member.
            'guild_tax' => (int) ($row->guild_tax ?? 0),
            'devotion' => (int) ($row->devotion ?? 0),
        ])->all();
    }

    /**
     * Allies or enemies.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Who the guild has expelled, and why.
     *
     * rAthena keeps the name as well as the account id, because the character
     * may since have been deleted and the name is all that is left of them.
     *
     * @return list<array<string, mixed>>
     */
    private function expulsions(int $guild): array
    {
        return $this->connections->connection($this->charMapConnection())
            ->table('guild_expulsion')
            ->select(['account_id', 'name', 'mes'])
            ->where('guild_id', $guild)
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'account_id' => (int) $row->account_id,
                'name' => (string) $row->name,
                'reason' => $this->clean($row->mes ?? ''),
            ])
            ->all();
    }

    /**
     * The guild's rank titles.
     *
     * Sent alongside the roster rather than only joined into it, so a client
     * can show the ranks a guild has defined even where nobody holds one.
     *
     * @return list<array<string, mixed>>
     */
    private function positions(int $guild): array
    {
        return $this->connections->connection($this->charMapConnection())
            ->table('guild_position')
            ->select(['position', 'name', 'mode', 'exp_mode'])
            ->where('guild_id', $guild)
            ->orderBy('position')
            ->get()
            ->map(fn (object $row): array => [
                'position' => (int) $row->position,
                'name' => $this->clean($row->name ?? ''),
                'mode' => (int) ($row->mode ?? 0),
                'guild_tax' => (int) ($row->exp_mode ?? 0),
            ])
            ->all();
    }

    /**
     * Guild storage contents, when the viewer may see them.
     *
     * Ports the `GStorageLeaderOnly` branch of modules/guild/view.php. Three
     * kinds of viewer may see a store: staff with ViewGuild, the guild master,
     * and -- unless the operator has restricted it -- any member of the guild.
     * Anyone else gets null, which is distinguishable from an empty store.
     *
     * @return list<array<string, mixed>>|null
     */
    private function storage(Request $request, int $guild, int $masterCharId): ?array
    {
        $account = $request->user();

        if (! $account instanceof Account) {
            return null;
        }

        if (! $this->maySeeStorage($account, $guild, $masterCharId)) {
            return null;
        }

        $limit = (int) config('panel.guilds.storage_limit', 500);

        return $this->stacks->read(
            $this->servers->currentCharMapServer(),
            'guild_storage',
            fn (Builder $query): Builder => $query
                ->where('guild_storage.guild_id', $guild)
                ->limit(max(1, $limit)),
            /*
             * Unidentified rows included only for staff, as everywhere else:
             * naming an unidentified item tells the viewer something its owner
             * does not know yet.
             */
            includeUnidentified: $account->can('SeeUnknownItems'),
        );
    }

    /**
     * Staff ability, then guild master, then ordinary membership.
     */
    private function maySeeStorage(Account $account, int $guild, int $masterCharId): bool
    {
        if ($account->can('ViewGuild')) {
            return true;
        }

        $characters = $this->connections->connection($this->charMapConnection())
            ->table('char')
            ->where('account_id', $account->account_id)
            ->where('guild_id', $guild)
            ->pluck('char_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($characters === []) {
            return false;
        }

        if ((bool) config('panel.guilds.storage_leader_only', false)) {
            return in_array($masterCharId, $characters, true);
        }

        return true;
    }

    /**
     * Strip rAthena's string terminator.
     *
     * The emulator pads some varchar columns with a literal `|00`, which the
     * legacy removed with REPLACE() in every query that selected one. Left in,
     * it shows up as three characters on the end of a guild notice.
     */
    private function clean(mixed $value): string
    {
        return str_replace('|00', '', (string) $value);
    }

    private function relations(int $guild, int $opposition): array
    {
        /*
         * The name is taken from `guild`, not from guild_alliance's own copy
         * of it. rAthena denormalises the ally's name into the alliance row
         * and never updates it, so a guild that has renamed appears under its
         * old name in every ally list that trusts that column. The legacy
         * joined for the same reason. The stored copy is the fallback, for an
         * ally row whose guild has since been disbanded.
         */
        return $this->connections->connection($this->charMapConnection())
            ->table('guild_alliance as a')
            ->leftJoin('guild as ally', 'ally.guild_id', '=', 'a.alliance_id')
            ->select(['a.alliance_id', 'a.name as stored_name', 'ally.name as current_name'])
            ->where('a.guild_id', $guild)
            ->where('a.opposition', $opposition)
            ->orderBy('a.alliance_id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->alliance_id,
                'name' => $this->clean($row->current_name ?? $row->stored_name ?? ''),
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
