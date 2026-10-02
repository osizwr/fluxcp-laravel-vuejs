<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Enums\AccountLevel;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

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
     * @param  list<array{0: string, 1: string}>  $orderBy
     * @return Collection<int, object>
     */
    private function ladder(
        array $orderBy,
        int $limit,
        ?int $jobClass = null,
        bool $hideOptedOut = false,
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
     *
     * @param  Builder  $query
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
