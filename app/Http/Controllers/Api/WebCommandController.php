<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Commands queued for the game server to run.
 *
 * Ports modules/webcommands/index.php. Rows in `cp_commands` are picked up by
 * an rAthena script and executed with game-master powers, which makes this the
 * most dangerous table the panel writes to.
 *
 * ---------------------------------------------------------------------------
 * What the legacy did
 * ---------------------------------------------------------------------------
 *
 *     $sth->execute(array($_POST['command'], $session->account->userid, ...));
 *
 * The command was inserted exactly as submitted, with no allow-list and no
 * validation. Anybody who could reach the page could queue any command the
 * script would run -- `@item`, `@zeny`, `@adjgroup` -- and the script would
 * run it as staff.
 *
 * ---------------------------------------------------------------------------
 * What this does instead
 * ---------------------------------------------------------------------------
 *
 * Off unless the operator turns it on, and the command must match one of the
 * patterns they configured. An empty allow-list accepts nothing, so enabling
 * the feature without listing anything is inert rather than open.
 *
 * See docs/MIGRATION_DECISIONS.md (D23).
 */
final class WebCommandController
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Commands this account has queued, and whether they have run.
     *
     * @throws ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->connection()
            ->table('cp_commands')
            ->select('id', 'command', 'issuer', 'done', 'timestamp')
            ->where('account_id', $account->account_id);

        $list = new ListQuery(
            sortable: ['queued' => 'timestamp'],
            defaultSort: 'queued',
        );

        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'command' => (string) $row->command,
                'queued_at' => $this->iso($row->timestamp),
                'executed' => (int) $row->done !== 0,
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'enabled' => config('panel.characters.web_commands.enabled') === true,
                // So the form can show what is accepted rather than only
                // rejecting what is not.
                'allowed' => array_values((array) config('panel.characters.web_commands.allowed', [])),
            ],
        ]);
    }

    /**
     * Queue a command.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if (config('panel.characters.web_commands.enabled') !== true) {
            return response()->json(['message' => 'Web commands are turned off.'], 403);
        }

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:128'],
        ]);

        $command = trim($validated['command']);

        if (! $this->isAllowed($command)) {
            return response()->json([
                'message' => 'That command is not one this server accepts from the website.',
            ], 422);
        }

        $this->connection()->table('cp_commands')->insert([
            'command' => $command,
            // From the session, so the audit row cannot name somebody else.
            'issuer' => $account->userid,
            'account_id' => $account->account_id,
            'done' => 0,
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'The command has been queued. It runs the next time the server picks it up.',
        ], 201);
    }

    /**
     * Whether a command matches one of the operator's patterns.
     *
     * Patterns are shell globs, so `@refresh` is an exact command and
     * `@storage*` a family. An empty list accepts nothing: turning the feature
     * on without configuring it leaves it inert rather than open.
     */
    private function isAllowed(string $command): bool
    {
        foreach ((array) config('panel.characters.web_commands.allowed', []) as $pattern) {
            $pattern = trim((string) $pattern);

            if ($pattern !== '' && fnmatch($pattern, $command, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return $account;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection(
            $this->servers->currentCharMapServer()->connectionName(),
        );
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
