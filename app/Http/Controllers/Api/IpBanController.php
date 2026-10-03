<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Auth\IpBanAdministration;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * IP bans.
 *
 * Ports modules/ipban/index.php, add.php, edit.php, remove.php and unban.php.
 *
 * Writing to `ipbanlist` affects the game server as well as the panel, so
 * every write here needs the matching ability on top of the route's staff
 * level: ModifyIpBan to add or edit, RemoveIpBan to lift.
 */
final class IpBanController
{
    public function __construct(private readonly IpBanAdministration $bans) {}

    /**
     * Current bans.
     *
     * @throws ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['pattern' => ['nullable', 'string', 'max:39']]);

        $query = $this->bans->query();

        if (($pattern = trim((string) $request->string('pattern'))) !== '') {
            $query->where('list', 'like', '%'.addcslashes($pattern, '%_\\').'%');
        }

        /*
         * Expired rows are kept rather than hidden. rAthena stops enforcing a
         * ban the moment `rtime` passes, but an administrator looking at this
         * page wants to see what was banned recently, and the `expired` flag
         * says which are still in force.
         */
        $list = new ListQuery(
            sortable: ['pattern' => 'list', 'banned' => 'btime', 'expires' => 'rtime'],
            defaultSort: 'banned',
        );

        $page = $list->paginate($query, $request);
        $now = CarbonImmutable::now();

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'pattern' => (string) $row->list,
                'reason' => (string) $row->reason,
                'banned_at' => $this->iso($row->btime),
                'expires_at' => $this->iso($row->rtime),
                'expired' => $row->rtime !== null
                    && CarbonImmutable::parse((string) $row->rtime)->isBefore($now),
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                // Published so the form can warn before submitting rather than
                // only on rejection.
                'whitelist' => (array) config('panel.ip_bans.whitelist', []),
            ],
        ]);
    }

    /**
     * Add a ban.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorise($request, 'ModifyIpBan');

        $validated = $request->validate([
            'pattern' => ['required', 'string', 'max:39'],
            'reason' => ['required', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'until' => ['nullable', 'date'],
        ]);

        $this->bans->add(
            pattern: trim($validated['pattern']),
            reason: trim($validated['reason']),
            until: $this->expiry($validated),
            bannedBy: $actor->account_id,
        );

        return response()->json(['message' => 'The IP ban has been added.'], 201);
    }

    /**
     * Change a ban.
     *
     * @throws ValidationException
     */
    public function update(Request $request, string $pattern): JsonResponse
    {
        $actor = $this->authorise($request, 'ModifyIpBan');

        $validated = $request->validate([
            'pattern' => ['required', 'string', 'max:39'],
            'reason' => ['required', 'string', 'max:255'],
            'edit_reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'until' => ['nullable', 'date'],
        ]);

        $changed = $this->bans->edit(
            currentPattern: $pattern,
            pattern: trim($validated['pattern']),
            reason: trim($validated['reason']),
            until: $this->expiry($validated),
            editedBy: $actor->account_id,
            editReason: trim((string) ($validated['edit_reason'] ?? '')),
        );

        abort_unless($changed, 404, 'There is no ban on that pattern.');

        return response()->json(['message' => 'The IP ban has been updated.']);
    }

    /**
     * Lift one or more bans.
     *
     * Takes a list because the legacy `unban` action did: an administrator
     * clearing up after an incident lifts a batch, and doing it one request at
     * a time writes a history row per click with no shared reason.
     *
     * @throws ValidationException
     */
    public function destroy(Request $request): JsonResponse
    {
        $actor = $this->authorise($request, 'RemoveIpBan');

        $validated = $request->validate([
            'patterns' => ['required', 'array', 'min:1', 'max:100'],
            'patterns.*' => ['required', 'string', 'max:39'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->bans->removeMany(
            patterns: array_map('trim', $validated['patterns']),
            reason: trim($validated['reason']),
            unbannedBy: $actor->account_id,
        );

        return response()->json([
            'message' => $result['lifted'] === []
                ? 'None of those patterns was banned.'
                : sprintf(
                    '%d ban%s lifted.',
                    count($result['lifted']),
                    count($result['lifted']) === 1 ? '' : 's',
                ),
            'lifted' => $result['lifted'],
            // Reported rather than silently ignored: a pattern that was not
            // there may mean somebody is working from a stale list.
            'not_found' => $result['missing'],
        ]);
    }

    private function authorise(Request $request, string $ability): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);
        abort_unless($account->can($ability), 403, 'You may not change IP bans.');

        return $account;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function expiry(array $validated): CarbonImmutable
    {
        if (($until = $validated['until'] ?? null) !== null) {
            return CarbonImmutable::parse((string) $until);
        }

        $days = (int) ($validated['days'] ?? config('panel.ip_bans.default_days', 7));

        return CarbonImmutable::now()->addDays(max(1, $days));
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601String();
    }
}
