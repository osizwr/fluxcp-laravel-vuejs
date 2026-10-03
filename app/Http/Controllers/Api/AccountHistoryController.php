<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\LoginFailure;
use App\Models\Account;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What has happened to the signed-in account.
 *
 * Ports modules/history/cplogin.php, gamelogin.php, passchange.php,
 * passreset.php and emailchange.php. The legacy `index` was a menu with no
 * data of its own and is a client route here.
 *
 * ---------------------------------------------------------------------------
 * Own account only
 * ---------------------------------------------------------------------------
 *
 * Every query here is scoped to `$request->user()`, never to an account id
 * from the request. These tables hold sign-in addresses and the times somebody
 * was at a keyboard, so an endpoint that took an id would be a way to follow
 * another player around. The legacy actions were scoped the same way; stating
 * it because the scoping is the whole security model of this controller.
 */
final class AccountHistoryController
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Sign-ins to this website.
     *
     * @throws ValidationException
     */
    public function panelLogins(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->login()
            ->table('cp_loginlog')
            ->select('ip', 'login_date', 'error_code')
            ->where('account_id', $account->account_id);

        return $this->paginate(
            $request,
            $query,
            new ListQuery(
                sortable: ['date' => 'login_date', 'ip' => 'ip', 'outcome' => 'error_code'],
                defaultSort: 'date',
            ),
            fn (object $row): array => [
                'ip' => (string) $row->ip,
                'at' => $this->timestamp($row->login_date),
                /*
                 * The stored code is FluxCP's Flux_LoginError value, which is
                 * why LoginFailure preserves those integers. A code this panel
                 * does not model is reported as unsuccessful rather than
                 * guessed at.
                 */
                'successful' => $row->error_code === null || (int) $row->error_code === 0,
                'outcome' => $this->describeLoginOutcome($row->error_code),
            ],
        );
    }

    /**
     * Sign-ins to the game.
     *
     * Read from rAthena's own `loginlog`, which is keyed on the account *name*
     * rather than its id. Case sensitivity follows the login server's setting,
     * because a server running case-insensitively will have rows written with
     * whatever capitalisation the player typed.
     *
     * @throws ValidationException
     */
    public function gameLogins(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $group = $this->servers->current();

        $query = $this->connections->connection($group->logsConnection())
            ->table('loginlog')
            ->select('time', 'ip', 'rcode', 'log');

        $group->loginServer->caseSensitive
            ? $query->whereRaw('CAST(user AS BINARY) = ?', [$account->userid])
            : $query->whereRaw('LOWER(user) = LOWER(?)', [$account->userid]);

        return $this->paginate(
            $request,
            $query,
            new ListQuery(
                sortable: ['date' => 'time', 'ip' => 'ip', 'outcome' => 'rcode'],
                defaultSort: 'date',
            ),
            fn (object $row): array => [
                'ip' => (string) $row->ip,
                'at' => $this->timestamp($row->time),
                'successful' => (int) ($row->rcode ?? 0) === 0,
                'outcome' => (string) ($row->log ?? ''),
            ],
        );
    }

    /**
     * @throws ValidationException
     */
    public function passwordChanges(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->login()
            ->table('cp_pwchange')
            ->select('change_date', 'change_ip')
            ->where('account_id', $account->account_id);

        return $this->paginate(
            $request,
            $query,
            new ListQuery(
                sortable: ['date' => 'change_date', 'ip' => 'change_ip'],
                defaultSort: 'date',
            ),
            fn (object $row): array => [
                'at' => $this->timestamp($row->change_date),
                'ip' => (string) $row->change_ip,
            ],
        );
    }

    /**
     * @throws ValidationException
     */
    public function passwordResets(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->login()
            ->table('cp_resetpass')
            ->select('request_date', 'request_ip', 'reset_date', 'reset_ip', 'reset_done')
            ->where('account_id', $account->account_id);

        return $this->paginate(
            $request,
            $query,
            new ListQuery(
                sortable: [
                    'requested' => 'request_date',
                    'completed' => 'reset_date',
                    'ip' => 'request_ip',
                ],
                defaultSort: 'requested',
            ),
            fn (object $row): array => [
                'requested_at' => $this->timestamp($row->request_date),
                'requested_from' => (string) $row->request_ip,
                'completed_at' => $this->timestamp($row->reset_date),
                'completed_from' => $row->reset_ip === null ? null : (string) $row->reset_ip,
                'completed' => (int) ($row->reset_done ?? 0) === 1,
            ],
        );
    }

    /**
     * @throws ValidationException
     */
    public function emailChanges(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->login()
            ->table('cp_emailchange')
            ->select('old_email', 'new_email', 'request_date', 'request_ip', 'change_date', 'change_ip', 'change_done')
            ->where('account_id', $account->account_id);

        return $this->paginate(
            $request,
            $query,
            new ListQuery(
                sortable: ['requested' => 'request_date', 'completed' => 'change_date'],
                defaultSort: 'requested',
            ),
            fn (object $row): array => [
                'from' => (string) $row->old_email,
                'to' => (string) $row->new_email,
                'requested_at' => $this->timestamp($row->request_date),
                'requested_from' => (string) $row->request_ip,
                'completed_at' => $this->timestamp($row->change_date),
                'completed' => (int) ($row->change_done ?? 0) === 1,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shared
    |--------------------------------------------------------------------------
    */

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return $account;
    }

    private function login(): ConnectionInterface
    {
        return $this->connections->connection($this->servers->current()->loginConnection());
    }

    /**
     * @throws ValidationException
     */
    private function paginate(
        Request $request,
        Builder $query,
        ListQuery $list,
        callable $shape,
    ): JsonResponse {
        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map($shape, $page->items()),
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
     * An ISO timestamp, or null.
     *
     * These columns are nullable and, on an old install, sometimes hold
     * MySQL's zero date. Both read as "nothing recorded" rather than as 1970.
     */
    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    private function describeLoginOutcome(mixed $code): string
    {
        if ($code === null || (int) $code === 0) {
            return 'Signed in';
        }

        $failure = LoginFailure::tryFrom((int) $code);

        return $failure === null
            ? 'Refused'
            : trans($failure->translationKey());
    }
}
