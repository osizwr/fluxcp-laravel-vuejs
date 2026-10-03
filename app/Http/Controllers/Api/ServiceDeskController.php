<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Support\ServiceDesk;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets, for the player who opened them and for the staff who
 * answer them.
 *
 * Ports modules/servicedesk/index.php, create.php, view.php, staffindex.php,
 * staffview.php, staffviewclosed.php, staffsettings.php and catcontrol.php.
 *
 * ---------------------------------------------------------------------------
 * The one rule
 * ---------------------------------------------------------------------------
 *
 * A player sees their own tickets and nobody else's. Every player-facing query
 * is scoped to `$request->user()`, and reading a ticket by id checks ownership
 * before returning anything — a support ticket contains whatever somebody
 * typed while frustrated, which is often more than they would say publicly.
 *
 * The legacy `create` action took the ticket's owner and originating address
 * from the submitted form, so a player could open a ticket as somebody else.
 * Both come from the session here.
 */
final class ServiceDeskController
{
    public function __construct(private readonly ServiceDesk $desk) {}

    /*
    |--------------------------------------------------------------------------
    | The player's side
    |--------------------------------------------------------------------------
    */

    /**
     * The signed-in account's tickets.
     *
     * @throws ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->desk->query()->where('t.account_id', $account->account_id);

        return $this->paginated($request, $query, [
            'categories' => $this->desk->categories(),
        ]);
    }

    /**
     * Open a ticket.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $categories = array_column($this->desk->categories(), 'id');

        $validated = $request->validate([
            'category' => ['required', 'integer', Rule::in($categories)],
            'subject' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:65535'],
            // 0 means "all characters", which is what the legacy form offered.
            'char_id' => ['nullable', 'integer', 'min:0'],
            'sslink' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'chatlink' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'videolink' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ], [
            'category.in' => 'Choose one of the available categories.',
        ]);

        /*
         * A character id, when given, has to belong to the person opening the
         * ticket. Otherwise the field is a way to attach somebody else's
         * character to your complaint.
         */
        if (($charId = (int) ($validated['char_id'] ?? 0)) > 0) {
            abort_unless(
                $account->characters()->whereKey($charId)->exists(),
                422,
                'That character does not belong to this account.',
            );
        }

        $ticketId = $this->desk->open($account, $validated, (string) $request->ip());

        return response()->json([
            'message' => 'Your ticket has been opened.',
            'data' => ['id' => $ticketId],
        ], 201);
    }

    /**
     * One of the account's own tickets, with its replies.
     */
    public function show(Request $request, int $ticket): JsonResponse
    {
        $account = $this->account($request);

        $row = $this->desk->find($ticket);

        /*
         * 404 rather than 403 for somebody else's ticket. A 403 confirms the
         * ticket exists, which over a range of ids is a count of how many
         * tickets the server has had.
         */
        abort_if($row === null || (int) $row->account_id !== $account->account_id, 404, 'No such ticket.');

        return response()->json(['data' => $this->shapeDetail($row)]);
    }

    /**
     * Reply to one of the account's own tickets.
     *
     * @throws ValidationException
     */
    public function reply(Request $request, int $ticket): JsonResponse
    {
        $account = $this->account($request);

        $row = $this->desk->find($ticket);

        abort_if($row === null || (int) $row->account_id !== $account->account_id, 404, 'No such ticket.');
        abort_if($row->status === 'Closed', 422, 'This ticket is closed. Open a new one.');

        $validated = $request->validate(['text' => ['required', 'string', 'max:65535']]);

        $this->desk->reply($ticket, $account, $validated['text'], false, (string) $request->ip());

        return response()->json(['message' => 'Your reply has been added.']);
    }

    /*
    |--------------------------------------------------------------------------
    | The staff side
    |--------------------------------------------------------------------------
    */

    /**
     * Open tickets, or closed ones.
     *
     * @throws ValidationException
     */
    public function staffIndex(Request $request): JsonResponse
    {
        $this->account($request);

        /*
         * From the route default on the closed queue, or from the query
         * elsewhere. The route wins, so `support/queue/closed?closed=0` cannot
         * be used to read the live queue through the archive's permission.
         */
        $closed = $request->route()?->defaults['closed'] ?? $request->boolean('closed');

        $query = $this->desk->query()->whereIn(
            't.status',
            $closed ? ['Closed'] : ServiceDesk::OPEN_STATUSES,
        );

        if (($category = $request->integer('category')) > 0) {
            $query->where('t.category', $category);
        }

        if (($status = trim((string) $request->string('status'))) !== '') {
            abort_unless(in_array($status, ServiceDesk::STATUSES, true), 422, 'Unknown status.');

            $query->where('t.status', $status);
        }

        return $this->paginated($request, $query, [
            'closed' => $closed,
            'categories' => $this->desk->categories(includeHidden: true),
            'statuses' => ServiceDesk::STATUSES,
        ]);
    }

    /**
     * Any ticket, for staff.
     */
    public function staffShow(Request $request, int $ticket): JsonResponse
    {
        $this->account($request);

        $row = $this->desk->find($ticket);

        abort_if($row === null, 404, 'No such ticket.');

        return response()->json([
            'data' => [
                ...$this->shapeDetail($row),
                // Staff additionally see where it was opened from and which
                // address the account had at the time.
                'opened_from' => (string) ($row->ip ?? ''),
                'account_email' => (string) ($row->curemail ?? ''),
            ],
        ]);
    }

    /**
     * Reply as staff, optionally changing the status in the same action.
     *
     * @throws ValidationException
     */
    public function staffReply(Request $request, int $ticket): JsonResponse
    {
        $account = $this->account($request);

        abort_if($this->desk->find($ticket) === null, 404, 'No such ticket.');

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:65535'],
            'status' => ['nullable', Rule::in(ServiceDesk::STATUSES)],
        ]);

        $this->desk->reply(
            $ticket,
            $account,
            $validated['text'],
            true,
            (string) $request->ip(),
            $validated['status'] ?? null,
        );

        if (($status = $validated['status'] ?? null) !== null) {
            $this->desk->setStatus($ticket, $status);
        }

        return response()->json(['message' => 'Your reply has been added.']);
    }

    /**
     * Each staff member's own preferences.
     *
     * @throws ValidationException
     */
    public function settings(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if ($request->isMethod('GET')) {
            return response()->json(['data' => $this->desk->settingsFor($account)]);
        }

        $validated = $request->validate([
            /*
             * The name replies are signed with. Staff are not required to
             * expose their account name to every player who opens a ticket.
             */
            'display_name' => ['sometimes', 'string', 'max:32'],
            'team' => ['sometimes', 'integer', 'min:1'],
            'email_alerts' => ['sometimes', 'boolean'],
        ]);

        $this->desk->saveSettings($account, $validated);

        return response()->json([
            'message' => 'Your support desk settings have been saved.',
            'data' => $this->desk->settingsFor($account),
        ]);
    }

    /**
     * Ticket categories.
     *
     * @throws ValidationException
     */
    public function categories(Request $request): JsonResponse
    {
        $this->account($request);

        if ($request->isMethod('GET')) {
            return response()->json(['data' => $this->desk->categories(includeHidden: true)]);
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required_without:hide', 'string', 'max:32'],
            'visible' => ['sometimes', 'boolean'],
            'hide' => ['sometimes', 'boolean'],
        ]);

        /*
         * Hidden rather than deleted. Tickets reference a category by id, so
         * removing the row would leave historic tickets pointing at nothing.
         */
        if (($validated['hide'] ?? false) && ($id = (int) ($validated['id'] ?? 0)) > 0) {
            abort_unless($this->desk->hideCategory($id), 404, 'No such category.');

            return response()->json(['message' => 'The category is no longer offered.']);
        }

        $visible = (bool) ($validated['visible'] ?? true);

        if (($id = (int) ($validated['id'] ?? 0)) > 0) {
            abort_unless(
                $this->desk->updateCategory($id, $validated['name'], $visible),
                404,
                'No such category.',
            );

            return response()->json(['message' => 'The category has been saved.']);
        }

        return response()->json([
            'message' => 'The category has been added.',
            'data' => ['id' => $this->desk->addCategory($validated['name'], $visible)],
        ], 201);
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

    /**
     * @param  array<string, mixed>  $extraMeta
     *
     * @throws ValidationException
     */
    private function paginated(Request $request, $query, array $extraMeta = []): JsonResponse
    {
        $list = new ListQuery(
            sortable: [
                'opened' => 't.timestamp',
                'status' => 't.status',
                'subject' => 't.subject',
            ],
            defaultSort: 'opened',
        );

        $page = $list->paginate($query, $request);

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->ticket_id,
                'subject' => (string) $row->subject,
                'status' => (string) $row->status,
                'category' => [
                    'id' => (int) $row->category,
                    'name' => (string) ($row->category_name ?? 'Uncategorised'),
                ],
                'opened_at' => $this->desk->iso($row->timestamp),
                'last_reply_by' => ($row->lastreply ?? '0') === '0' ? null : (string) $row->lastreply,
                'account_id' => (int) $row->account_id,
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                ...$extraMeta,
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeDetail(object $row): array
    {
        return [
            'id' => (int) $row->ticket_id,
            'subject' => (string) $row->subject,
            'text' => (string) $row->text,
            'status' => (string) $row->status,
            'category' => [
                'id' => (int) $row->category,
                'name' => (string) ($row->category_name ?? 'Uncategorised'),
            ],
            'character_id' => (int) $row->char_id > 0 ? (int) $row->char_id : null,
            'links' => array_values(array_filter([
                $this->link($row->sslink ?? null),
                $this->link($row->chatlink ?? null),
                $this->link($row->videolink ?? null),
            ])),
            'opened_at' => $this->desk->iso($row->timestamp),
            'replies' => $this->desk->replies((int) $row->ticket_id),
        ];
    }

    /**
     * A supporting link, or null.
     *
     * The legacy wrote the string `'0'` when a link field was left empty, so
     * that value means "none" rather than being a URL.
     */
    private function link(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' || $value === '0' ? null : $value;
    }
}
