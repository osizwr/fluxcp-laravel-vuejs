<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Models\Account;
use App\Models\Character;
use App\Services\Rathena\ReferenceData;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Staff search over accounts and characters.
 *
 * Ports modules/account/index.php and modules/character/index.php, which were
 * FluxCP's two admin search screens.
 *
 * ---------------------------------------------------------------------------
 * One filter the legacy had that this does not
 * ---------------------------------------------------------------------------
 *
 * `account/index` accepted a `password` parameter and searched `login.user_pass`
 * with it. On a server storing cleartext that is a way for any junior staff
 * member to find every account sharing a password, and to confirm a guess
 * against the whole player base at once. It is not ported, and the permission
 * map already records the related `SearchCpChangePass` ability as NOONE for
 * the same reason. See docs/MIGRATION_DECISIONS.md (D22).
 */
final class AdminSearchController
{
    private const OPERATORS = ['eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
        private readonly ReferenceData $reference,
    ) {}

    /**
     * Search accounts.
     *
     * @throws ValidationException
     */
    public function accounts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:23'],
            'email' => ['nullable', 'string', 'max:39'],
            'last_ip' => ['nullable', 'string', 'max:39'],
            'gender' => ['nullable', 'string', 'in:M,F,S'],
            'state' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'group_id_op' => ['nullable', 'in:'.implode(',', array_keys(self::OPERATORS))],
            'login_count' => ['nullable', 'integer'],
            'login_count_op' => ['nullable', 'in:'.implode(',', array_keys(self::OPERATORS))],
            'last_login_after' => ['nullable', 'date'],
            'last_login_before' => ['nullable', 'date'],
            'birthdate_after' => ['nullable', 'date'],
            'birthdate_before' => ['nullable', 'date'],
        ]);

        $group = $this->servers->current();

        $query = $this->connections->connection($group->loginConnection())
            ->table('login')
            ->select([
                'account_id', 'userid', 'email', 'sex', 'group_id', 'state',
                'unban_time', 'expiration_time', 'logincount', 'lastlogin',
                'last_ip', 'birthdate',
            ]);

        $this->applyAccountFilters($query, $filters);

        $list = new ListQuery(
            sortable: [
                'id' => 'account_id',
                'username' => 'userid',
                'email' => 'email',
                'group' => 'group_id',
                'logins' => 'logincount',
                'last_login' => 'lastlogin',
            ],
            defaultSort: 'id',
            defaultDirection: 'asc',
        );

        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->account_id,
                'username' => (string) $row->userid,
                'email' => (string) $row->email,
                'gender' => (string) $row->sex,
                'group_id' => (int) $row->group_id,
                'group_name' => $this->groupName((int) $row->group_id),
                'state' => (int) $row->state,
                'login_count' => (int) $row->logincount,
                'last_login_at' => $this->iso($row->lastlogin),
                'last_ip' => (string) $row->last_ip,
                'birthdate' => $row->birthdate === null ? null : (string) $row->birthdate,
                // Never selected, so there is nothing to omit here by accident.
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'operators' => array_keys(self::OPERATORS),
            ],
        ]);
    }

    /**
     * Search characters.
     *
     * @throws ValidationException
     */
    public function characters(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'char_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:30'],
            'class' => ['nullable', 'integer'],
            'guild_id' => ['nullable', 'integer'],
            'online' => ['nullable', 'boolean'],
            'slot' => ['nullable', 'integer'],
            'base_level' => ['nullable', 'integer'],
            'base_level_op' => ['nullable', 'in:'.implode(',', array_keys(self::OPERATORS))],
            'job_level' => ['nullable', 'integer'],
            'job_level_op' => ['nullable', 'in:'.implode(',', array_keys(self::OPERATORS))],
            'zeny' => ['nullable', 'integer'],
            'zeny_op' => ['nullable', 'in:'.implode(',', array_keys(self::OPERATORS))],
        ]);

        $server = $this->servers->currentCharMapServer();

        $query = $this->connections->connection($server->connectionName())
            ->table('char as ch')
            ->select([
                'ch.char_id', 'ch.account_id', 'ch.char_num', 'ch.name', 'ch.class',
                'ch.base_level', 'ch.job_level', 'ch.zeny', 'ch.online',
                'ch.guild_id', 'ch.last_map', 'ch.delete_date',
                'guild.name as guild_name',
            ])
            ->leftJoin('guild', 'guild.guild_id', '=', 'ch.guild_id');

        $this->applyCharacterFilters($query, $filters);

        $list = new ListQuery(
            sortable: [
                'id' => 'ch.char_id',
                'name' => 'ch.name',
                'base_level' => 'ch.base_level',
                'job_level' => 'ch.job_level',
                'zeny' => 'ch.zeny',
            ],
            defaultSort: 'id',
            defaultDirection: 'asc',
        );

        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->char_id,
                'account_id' => (int) $row->account_id,
                'name' => (string) $row->name,
                'slot' => (int) $row->char_num,
                'job_id' => (int) $row->class,
                'job_name' => $this->reference->jobName((int) $row->class),
                'base_level' => (int) $row->base_level,
                'job_level' => (int) $row->job_level,
                'zeny' => (int) $row->zeny,
                'online' => (int) $row->online !== 0,
                'map' => (string) $row->last_map,
                'guild' => (int) $row->guild_id > 0
                    ? ['id' => (int) $row->guild_id, 'name' => (string) ($row->guild_name ?? '')]
                    : null,
                'pending_deletion' => (int) ($row->delete_date ?? 0) > 0,
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'operators' => array_keys(self::OPERATORS),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyAccountFilters(Builder $query, array $filters): void
    {
        $this->exact($query, $filters, 'account_id', 'account_id');
        $this->like($query, $filters, 'username', 'userid');
        $this->like($query, $filters, 'email', 'email');
        $this->like($query, $filters, 'last_ip', 'last_ip');

        if (($gender = $filters['gender'] ?? null) !== null) {
            $query->where('sex', Gender::from((string) $gender)->value);
        }

        $this->exact($query, $filters, 'state', 'state');
        $this->compare($query, $filters, 'group_id', 'group_id');
        $this->compare($query, $filters, 'login_count', 'logincount');

        $this->after($query, $filters, 'last_login_after', 'lastlogin');
        $this->before($query, $filters, 'last_login_before', 'lastlogin');
        $this->after($query, $filters, 'birthdate_after', 'birthdate');
        $this->before($query, $filters, 'birthdate_before', 'birthdate');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyCharacterFilters(Builder $query, array $filters): void
    {
        $this->exact($query, $filters, 'char_id', 'ch.char_id');
        $this->exact($query, $filters, 'account_id', 'ch.account_id');
        $this->like($query, $filters, 'name', 'ch.name');
        $this->exact($query, $filters, 'class', 'ch.class');
        $this->exact($query, $filters, 'guild_id', 'ch.guild_id');
        $this->exact($query, $filters, 'slot', 'ch.char_num');

        if (array_key_exists('online', $filters) && $filters['online'] !== null) {
            $query->where('ch.online', filter_var($filters['online'], FILTER_VALIDATE_BOOL) ? 1 : 0);
        }

        $this->compare($query, $filters, 'base_level', 'ch.base_level');
        $this->compare($query, $filters, 'job_level', 'ch.job_level');
        $this->compare($query, $filters, 'zeny', 'ch.zeny');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function exact(Builder $query, array $filters, string $key, string $column): void
    {
        if (($value = $filters[$key] ?? null) !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function like(Builder $query, array $filters, string $key, string $column): void
    {
        $value = trim((string) ($filters[$key] ?? ''));

        if ($value !== '') {
            // Wildcards escaped so a search for "%" does not return everything.
            $query->where($column, 'like', '%'.addcslashes($value, '%_\\').'%');
        }
    }

    /**
     * A numeric filter with a named operator.
     *
     * The operator arrives as `gte`, not `>=`, and is looked up — so nothing
     * from the request reaches SQL as an operator.
     *
     * @param  array<string, mixed>  $filters
     */
    private function compare(Builder $query, array $filters, string $key, string $column): void
    {
        $value = $filters[$key] ?? null;

        if ($value === null || $value === '') {
            return;
        }

        $operator = (string) ($filters["{$key}_op"] ?? 'eq');

        $query->where($column, self::OPERATORS[$operator] ?? '=', (int) $value);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function after(Builder $query, array $filters, string $key, string $column): void
    {
        if (($value = $filters[$key] ?? null) !== null && $value !== '') {
            $query->where($column, '>=', Carbon::parse((string) $value)->startOfDay());
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function before(Builder $query, array $filters, string $key, string $column): void
    {
        if (($value = $filters[$key] ?? null) !== null && $value !== '') {
            $query->where($column, '<=', Carbon::parse((string) $value)->endOfDay());
        }
    }

    private function groupName(int $groupId): string
    {
        $groups = (array) config('permissions.account_groups', []);

        return (string) ($groups[$groupId]['name'] ?? "Group {$groupId}");
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
