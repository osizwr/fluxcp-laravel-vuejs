<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Enums\AccountLevel;
use App\Services\Rathena\ReferenceTables;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the character ladders.
 *
 * Every ladder has to exclude accounts rather than characters: a banned
 * account's characters, and staff characters, must not appear. That means
 * joining `char` to `login` -- which live in different databases, and may live
 * on different servers.
 *
 * FluxCP assumed they never did, because it interpolated database names
 * straight into its SQL, so its rankings simply fail on a split setup. This
 * service handles both: a cross-database join when the two share a MySQL
 * server, and an exclusion set computed separately when they do not.
 */
final readonly class RankingService
{
    public function __construct(
        private ServerRegistry $servers,
        private ConnectionResolverInterface $connections,
        private PermissionRegistry $permissions,
    ) {}

    /**
     * The character level ladder.
     *
     * @return Collection<int, object>
     */
    public function byLevel(int $limit, ?int $jobClass = null): Collection
    {
        return $this->ladder(
            orderBy: [
                ['base_level', 'desc'],
                ['base_exp', 'desc'],
                ['job_level', 'desc'],
                ['job_exp', 'desc'],
                ['char_id', 'asc'],
            ],
            limit: $limit,
            jobClass: $jobClass,
        );
    }

    /**
     * The zeny ladder.
     *
     * Additionally honours the per-character HideFromZenyRanking preference,
     * which players set themselves so that being wealthy does not advertise
     * them as a target.
     *
     * @return Collection<int, object>
     */
    public function byZeny(int $limit, ?int $jobClass = null): Collection
    {
        return $this->ladder(
            orderBy: [
                ['zeny', 'desc'],
                ['char_id', 'asc'],
            ],
            limit: $limit,
            jobClass: $jobClass,
            hideOptedOut: true,
        );
    }

    /**
     * A fame ladder: alchemist or blacksmith.
     *
     * rAthena awards fame to these branches and the ladder is ordered by it,
     * with level and experience breaking ties the way the legacy ordering did.
     * Only characters with fame above zero appear -- a ladder padded with
     * everyone who has none is not a ladder.
     *
     * The class list includes the rebirth, baby and third-class variants,
     * because a ladder matching only the base class is empty on a mature
     * server.
     *
     * @return Collection<int, object>
     */
    public function byFame(string $branch, int $limit): Collection
    {
        $classes = array_keys((array) config("rathena_reference.fame_classes.{$branch}", []));

        if ($classes === []) {
            throw new InvalidArgumentException(
                "Unknown fame ladder [{$branch}]. Expected one of: "
                .implode(', ', array_keys((array) config('rathena_reference.fame_classes', []))).'.'
            );
        }

        return $this->ladder(
            orderBy: [
                ['fame', 'desc'],
                ['base_level', 'desc'],
                ['base_exp', 'desc'],
                ['job_level', 'desc'],
                ['job_exp', 'desc'],
                ['char_id', 'asc'],
            ],
            limit: $limit,
            classes: $classes,
            requireFame: true,
        );
    }

    /**
     * The death ladder.
     *
     * rAthena keeps the count in `char_reg_num` under the key
     * `PC_DIE_COUNTER`, not as a column, so it is a left join and a cast. A
     * character who has never died has no row at all, which is why the count
     * coalesces to zero rather than the join being an inner one -- the legacy
     * ladder listed those characters too.
     *
     * @return Collection<int, object>
     */
    public function byDeaths(int $limit, ?int $jobClass = null): Collection
    {
        return $this->ladder(
            orderBy: [],
            limit: $limit,
            jobClass: $jobClass,
            configure: function (Builder $query): void {
                $query
                    ->leftJoin('char_reg_num as deaths', function ($join): void {
                        $join->on('deaths.char_id', '=', 'ch.char_id')
                            // `key` is reserved in MySQL and must stay quoted,
                            // which the builder does.
                            ->where('deaths.key', '=', 'PC_DIE_COUNTER');
                    })
                    ->addSelect(DB::raw('CAST(IFNULL(deaths.value, 0) AS UNSIGNED) as death_count'))
                    ->orderByDesc('death_count')
                    ->orderByDesc('ch.char_id');
            },
        );
    }

    /**
     * The homunculus ladder.
     *
     * Keyed on the homunculus rather than the character, so it starts from a
     * different table and joins back to `char` for the owner.
     *
     * @return Collection<int, object>
     */
    public function byHomunculus(int $limit): Collection
    {
        $group = $this->servers->current();
        $pair = $this->servers->currentCharMapServer();
        $connection = $this->connections->connection($pair->connectionName());

        $query = $connection->table('homunculus as hm')
            ->select([
                'hm.homun_id',
                'hm.char_id',
                'hm.name as homunculus_name',
                'hm.class',
                'hm.level',
                'hm.exp',
                'hm.intimacy',
                'ch.name as owner_name',
                'ch.char_id as owner_char_id',
                'ch.guild_id',
                'guild.name as guild_name',
                'guild.emblem_id as guild_emblem_id',
            ])
            ->join('char as ch', 'ch.char_id', '=', 'hm.char_id')
            ->leftJoin('guild', 'guild.guild_id', '=', 'ch.guild_id')
            ->where(fn (Builder $q) => $q->where('ch.delete_date', 0)->orWhereNull('ch.delete_date'))
            // A homunculus that has been released is still in the table.
            ->where('hm.alive', 1);

        $this->applyAccountFilters($query, $group, $pair->key);

        return Collection::make(
            $query
                ->orderByDesc('hm.level')
                ->orderByDesc('hm.exp')
                ->orderByDesc('hm.intimacy')
                ->orderBy('hm.homun_id')
                ->limit($limit)
                ->get(),
        );
    }

    /**
     * The guild ladder.
     *
     * `guild.exp` is not reliable on every server -- some scripts write member
     * contributions without updating it -- so the legacy ladder took the
     * greater of the stored value and the sum of its members, and this does
     * the same. Member and castle counts are subqueries for the same reason
     * the legacy used them: the columns on `guild` drift.
     *
     * @return Collection<int, object>
     */
    public function byGuild(int $limit): Collection
    {
        $pair = $this->servers->currentCharMapServer();
        $connection = $this->connections->connection($pair->connectionName());

        $members = $connection->table('char')
            ->selectRaw('count(char_id)')
            ->whereColumn('char.guild_id', 'g.guild_id');

        $castles = $connection->table('guild_castle')
            ->selectRaw('count(castle_id)')
            ->whereColumn('guild_castle.guild_id', 'g.guild_id');

        $memberExp = $connection->table('guild_member')
            ->selectRaw('sum(exp)')
            ->whereColumn('guild_member.guild_id', 'g.guild_id');

        $query = $connection->table('guild as g')
            ->select([
                'g.guild_id',
                'g.name',
                'g.guild_lv',
                'g.max_member',
                'g.average_lv',
                'g.emblem_id',
                'g.master',
            ])
            ->selectSub($members, 'member_count')
            ->selectSub($castles, 'castle_count')
            ->selectRaw('GREATEST(g.exp, IFNULL(('.$memberExp->toSql().'), 0)) as guild_exp')
            ->addBinding($memberExp->getBindings(), 'select');

        return Collection::make(
            $query
                ->orderByDesc('g.guild_lv')
                ->orderByDesc('castle_count')
                ->orderByDesc('guild_exp')
                ->orderByDesc('g.average_lv')
                ->orderBy('g.guild_id')
                ->limit($limit)
                ->get(),
        );
    }

    /**
     * The MVP ladder: who has killed the most of a given boss.
     *
     * Assembled across three connections rather than in one query, because
     * they need not be the same database: the kill counts are in `mvplog` on
     * the logs connection, the killers' names on the char/map one, and the
     * monster names come from the merge so a custom MVP is named correctly.
     * An operator who puts logs on another host -- which is common, the table
     * grows fast -- would otherwise get a cross-database join that cannot run.
     *
     * The legacy module did the same, for the same reason.
     *
     * @return Collection<int, object>
     */
    public function byMvp(int $limit, ?int $monsterId = null): Collection
    {
        $group = $this->servers->current();
        $pair = $this->servers->currentCharMapServer();

        $logs = $this->connections->connection($group->logsConnection());
        $charMap = $this->connections->connection($pair->connectionName());

        $kills = $logs->table('mvplog')
            ->select('kill_char_id', 'monster_id')
            ->selectRaw('count(*) as kill_count')
            ->when($monsterId !== null, fn (Builder $q) => $q->where('monster_id', $monsterId))
            ->groupBy('kill_char_id', 'monster_id')
            ->orderByDesc('kill_count')
            // Over-fetched, because staff kills are removed afterwards and
            // trimming to the limit first would leave the ladder short.
            ->limit($limit * 4)
            ->get();

        if ($kills->isEmpty()) {
            return Collection::make();
        }

        $characters = $this->visibleCharacters(
            $charMap,
            $group,
            $pair->key,
            $kills->pluck('kill_char_id')->unique()->all(),
        );

        $monsters = $this->monsterNames($kills->pluck('monster_id')->unique()->all());

        return Collection::make($kills)
            // A kill by a character that no longer exists, or by staff, has no
            // entry here and drops out.
            ->filter(fn (object $kill): bool => isset($characters[$kill->kill_char_id]))
            ->map(function (object $kill) use ($characters, $monsters): object {
                $character = $characters[$kill->kill_char_id];

                return (object) [
                    'char_id' => (int) $kill->kill_char_id,
                    'name' => $character->name,
                    'class' => (int) $character->class,
                    'base_level' => (int) $character->base_level,
                    'guild_id' => (int) ($character->guild_id ?? 0),
                    'guild_name' => $character->guild_name,
                    'guild_emblem_id' => $character->guild_emblem_id,
                    'monster_id' => (int) $kill->monster_id,
                    'monster_name' => $monsters[(int) $kill->monster_id] ?? null,
                    'kill_count' => (int) $kill->kill_count,
                ];
            })
            ->values()
            ->take($limit);
    }

    /**
     * The characters among these ids that a ladder may show, keyed by id.
     *
     * @param  list<int|string>  $charIds
     * @return array<int, object>
     */
    private function visibleCharacters(
        $connection,
        ServerGroup $group,
        string $pairKey,
        array $charIds,
    ): array {
        $query = $connection->table('char as ch')
            ->select([
                'ch.char_id',
                'ch.name',
                'ch.class',
                'ch.base_level',
                'ch.guild_id',
                'guild.name as guild_name',
                'guild.emblem_id as guild_emblem_id',
            ])
            ->leftJoin('guild', 'guild.guild_id', '=', 'ch.guild_id')
            ->whereIn('ch.char_id', $charIds)
            ->where(fn (Builder $q) => $q->where('ch.delete_date', 0)->orWhereNull('ch.delete_date'));

        // The same staff, ban and inactivity exclusions every other ladder
        // applies, so one ladder cannot leak what the others hide.
        $this->applyAccountFilters($query, $group, $pairKey);

        return $query->get()->keyBy('char_id')->all();
    }

    /**
     * Monster names for these ids, from the merged table so a custom MVP is
     * named as the server named it.
     *
     * @param  list<int|string>  $monsterIds
     * @return array<int, string>
     */
    private function monsterNames(array $monsterIds): array
    {
        $tables = app(ReferenceTables::class);
        $columns = $tables->monsterColumns();

        $pick = static function (array $candidates, string $fallback) use ($columns): string {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    return $candidate;
                }
            }

            return $fallback;
        };

        $idColumn = $pick(['ID', 'id'], 'id');
        $nameColumn = $pick(['iName', 'name_english', 'kName'], 'name_english');

        return $tables->monsters()
            ->whereIn($idColumn, $monsterIds)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->{$idColumn} => (string) $row->{$nameColumn},
            ])
            ->all();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $orderBy
     * @return Collection<int, object>
     */
    private function ladder(
        array $orderBy,
        int $limit,
        ?int $jobClass = null,
        bool $hideOptedOut = false,
        array $classes = [],
        bool $requireFame = false,
        ?Closure $configure = null,
    ): Collection {
        $group = $this->servers->current();
        $pair = $this->servers->currentCharMapServer();
        $connection = $this->connections->connection($pair->connectionName());

        $query = $connection->table('char', 'ch')
            ->select([
                'ch.char_id',
                'ch.name',
                'ch.class',
                'ch.base_level',
                'ch.base_exp',
                'ch.job_level',
                'ch.job_exp',
                'ch.zeny',
                'ch.fame',
                'ch.guild_id',
                'guild.name as guild_name',
                'guild.emblem_id as guild_emblem_id',
            ])
            ->leftJoin('guild', 'guild.guild_id', '=', 'ch.guild_id')
            // Characters queued for deletion are not in the world any more.
            ->where(fn (Builder $q) => $q->where('ch.delete_date', 0)->orWhereNull('ch.delete_date'));

        if ($jobClass !== null) {
            $query->where('ch.class', $jobClass);
        }

        if ($classes !== []) {
            $query->whereIn('ch.class', $classes);
        }

        if ($requireFame) {
            $query->where('ch.fame', '>', 0);
        }

        // Lets a ladder add its own joins, columns and ordering without this
        // method growing a parameter per ladder.
        $configure?->call($this, $query);

        if ($hideOptedOut) {
            $query
                ->leftJoin('cp_charprefs as opt_out', function ($join): void {
                    $join->on('opt_out.char_id', '=', 'ch.char_id')
                        ->where('opt_out.name', '=', 'HideFromZenyRanking');
                })
                ->where(function (Builder $q): void {
                    $q->whereNull('opt_out.value')->orWhere('opt_out.value', '!=', '1');
                });
        }

        $this->applyAccountFilters($query, $group, $pair->key);

        foreach ($orderBy as [$column, $direction]) {
            $query->orderBy("ch.{$column}", $direction);
        }

        return Collection::make($query->limit($limit)->get());
    }

    /**
     * Exclude characters whose account is banned, stale, or staff.
     */
    private function applyAccountFilters(Builder $query, ServerGroup $group, string $pairKey): void
    {
        if ($group->loginAndCharMapShareServer($pairKey)) {
            $this->applyAccountFiltersByJoin($query, $group);

            return;
        }

        $query->whereNotIn('ch.account_id', $this->excludedAccountIds($group));
    }

    /**
     * The co-located case: join `login` by its qualified database name.
     */
    private function applyAccountFiltersByJoin(Builder $query, ServerGroup $group): void
    {
        $loginTable = $group->loginDatabaseName().'.login';

        $query->leftJoin("{$loginTable} as acct", 'acct.account_id', '=', 'ch.account_id');

        if (config('panel.rankings.hide_permanently_banned') === true) {
            $query->where('acct.state', '!=', 5);
        }

        if (config('panel.rankings.hide_temporarily_banned') === true) {
            $query->where(fn (Builder $q) => $q->whereNull('acct.unban_time')->orWhere('acct.unban_time', 0));
        }

        $visibleGroups = $this->visibleAccountGroupIds();

        if ($visibleGroups !== []) {
            $query->whereIn('acct.group_id', $visibleGroups);
        }

        $days = (int) config('panel.rankings.inactive_after_days', 0);

        if ($days > 0) {
            $query->whereRaw('TIMESTAMPDIFF(DAY, acct.lastlogin, NOW()) <= ?', [$days]);
        }
    }

    /**
     * The split case: compute the account ids to leave out.
     *
     * The excluded set is asked for rather than the included one, because on
     * any real server far fewer accounts are banned or staff than are
     * ordinary players.
     *
     * @return list<int>
     */
    private function excludedAccountIds(ServerGroup $group): array
    {
        $login = $this->connections->connection($group->loginConnection());
        $visibleGroups = $this->visibleAccountGroupIds();
        $days = (int) config('panel.rankings.inactive_after_days', 0);

        return $login->table('login')
            ->where(function (Builder $q) use ($visibleGroups, $days): void {
                if (config('panel.rankings.hide_permanently_banned') === true) {
                    $q->orWhere('state', 5);
                }

                if (config('panel.rankings.hide_temporarily_banned') === true) {
                    $q->orWhere('unban_time', '>', 0);
                }

                if ($visibleGroups !== []) {
                    $q->orWhereNotIn('group_id', $visibleGroups);
                }

                if ($days > 0) {
                    $q->orWhereRaw('TIMESTAMPDIFF(DAY, lastlogin, NOW()) > ?', [$days]);
                }
            })
            ->pluck('account_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * rAthena group ids whose characters may appear in a ladder.
     *
     * Staff are hidden above a configured level, as FluxCP's
     * RankingHideGroupLevel did, so a game master with developer-granted
     * levels or zeny does not sit at the top of a player ladder.
     *
     * An empty result means "no filtering", matching the legacy behaviour of
     * skipping the clause when the group list came back empty.
     *
     * @return list<int>
     */
    private function visibleAccountGroupIds(): array
    {
        $threshold = config('panel.rankings.hide_at_or_above_level');

        if ($threshold === null) {
            return [];
        }

        $cutoff = (int) $threshold;
        $visible = [];

        foreach ($this->permissions->accountGroupMap() as $groupId => $group) {
            /** @var AccountLevel $level */
            $level = $group['level'];

            if ($level->value < $cutoff) {
                $visible[] = $groupId;
            }
        }

        return $visible;
    }
}
