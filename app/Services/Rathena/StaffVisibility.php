<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Rathena\ServerGroup;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Leaving staff out of a public aggregate.
 *
 * Several public figures are distorted or made unsafe by counting staff: a
 * ladder headed by a game master with developer-granted levels, a map count of
 * one that locates a game master nobody else can see, a total-zeny figure that
 * moves because somebody granted themselves two billion for a test. FluxCP had
 * a separate option for each -- RankingHideGroupLevel, HideFromMapStats,
 * InfoHideZenyGroupLevel -- and the filter itself was copied three times.
 *
 * ---------------------------------------------------------------------------
 * Why this is not just a join
 * ---------------------------------------------------------------------------
 *
 * The group an account belongs to is in `login`, and the characters being
 * counted are in `char`. Those are two databases, and an operator may put them
 * on two servers, so there is no join that always works:
 *
 *   - co-located: join `login` by its qualified database name, which is one
 *     query and lets the database do the work.
 *   - split: ask the login connection which accounts to leave out, then
 *     exclude those ids. The excluded set is asked for rather than the
 *     included one, because on any real server far fewer accounts are staff
 *     than are players.
 *
 * Either way an empty exclusion list means the filter is off and nothing is
 * excluded. Filtering a count against an empty set of visible groups would
 * report every map as empty and every total as zero, which is the failure mode
 * the legacy avoided by skipping the clause.
 */
final readonly class StaffVisibility
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private PermissionRegistry $permissions,
    ) {}

    /**
     * Restrict a query over `char` to the accounts a public figure may count.
     *
     * @param  Builder  $query  A query whose `char` table is aliased.
     * @param  string  $alias  That alias, so the account id can be matched.
     * @param  int|null  $cutoff  Groups at or above this panel level are left
     *                            out. Null counts everybody.
     */
    public function excludeStaff(
        Builder $query,
        ServerGroup $group,
        ?string $charMapKey,
        string $alias,
        ?int $cutoff,
    ): void {
        $visible = $this->permissions->accountGroupIdsBelowLevel($cutoff);

        if ($visible === []) {
            return;
        }

        if ($group->loginAndCharMapShareServer($charMapKey)) {
            $login = $group->loginDatabaseName().'.login';

            $query->join("{$login} as visible_acct", 'visible_acct.account_id', '=', "{$alias}.account_id")
                ->whereIn('visible_acct.group_id', $visible);

            return;
        }

        $query->whereNotIn("{$alias}.account_id", $this->staffAccountIds($group, $visible));
    }

    /**
     * The accounts whose group is not in the visible set.
     *
     * @param  list<int>  $visibleGroupIds
     * @return list<int>
     */
    private function staffAccountIds(ServerGroup $group, array $visibleGroupIds): array
    {
        return $this->connections->connection($group->loginConnection())
            ->table('login')
            ->whereNotIn('group_id', $visibleGroupIds)
            ->pluck('account_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
