<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Support tickets.
 *
 * Ports the four `cp_servicedesk*` tables FluxCP created: the tickets, their
 * replies, the categories, and each staff member's own preferences.
 *
 * ---------------------------------------------------------------------------
 * Who a ticket belongs to
 * ---------------------------------------------------------------------------
 *
 * The legacy `create` action read the owner and the originating address
 * straight out of the submitted form:
 *
 *     $sth->execute(array($_POST['account_id'], ..., $_POST['ip'], ...));
 *
 * So a player could open a ticket as somebody else and record whatever address
 * they liked against it. Both come from the session and the request here, and
 * neither is accepted from the body.
 */
final readonly class ServiceDesk
{
    /**
     * The statuses a ticket moves through.
     *
     * Stored as the legacy strings so an existing FluxCP install's tickets
     * keep their state, rather than as an enum column the old panel cannot
     * read.
     */
    public const STATUSES = ['Pending', 'In Progress', 'Answered', 'Closed'];

    public const OPEN_STATUSES = ['Pending', 'In Progress', 'Answered'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
    ) {}

    /**
     * Categories an operator has chosen to show.
     *
     * @return list<array{id: int, name: string}>
     */
    public function categories(bool $includeHidden = false): array
    {
        return $this->connection()
            ->table('cp_servicedeskcat')
            ->when(! $includeHidden, fn (Builder $q) => $q->where('display', 1))
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->cat_id,
                'name' => (string) $row->name,
                'visible' => (int) $row->display !== 0,
            ])
            ->all();
    }

    /**
     * A query over tickets, unscoped. Callers narrow it.
     */
    public function query(): Builder
    {
        return $this->connection()
            ->table('cp_servicedesk as t')
            ->select([
                't.ticket_id', 't.account_id', 't.category', 't.status', 't.char_id',
                't.timestamp', 't.subject', 't.team', 't.lastreply',
            ])
            ->leftJoin('cp_servicedeskcat as c', 'c.cat_id', '=', 't.category')
            ->addSelect('c.name as category_name');
    }

    /**
     * One ticket, or null.
     */
    public function find(int $ticketId): ?object
    {
        return $this->connection()
            ->table('cp_servicedesk as t')
            ->select(['t.*', 'c.name as category_name'])
            ->leftJoin('cp_servicedeskcat as c', 'c.cat_id', '=', 't.category')
            ->where('t.ticket_id', $ticketId)
            ->first();
    }

    /**
     * Open a ticket.
     *
     * @param  array{category: int, subject: string, text: string, char_id?: int|null, sslink?: string|null, chatlink?: string|null, videolink?: string|null}  $fields
     */
    public function open(Account $account, array $fields, string $ip): int
    {
        return (int) $this->connection()->table('cp_servicedesk')->insertGetId([
            // From the session, never from the body.
            'account_id' => $account->account_id,
            'ip' => mb_substr($ip, 0, 39),
            'curemail' => $account->email,

            'char_id' => (string) ($fields['char_id'] ?? 0),
            'category' => $fields['category'],
            'subject' => $fields['subject'],
            'text' => $fields['text'],
            'sslink' => (string) ($fields['sslink'] ?? ''),
            'chatlink' => (string) ($fields['chatlink'] ?? ''),
            'videolink' => (string) ($fields['videolink'] ?? ''),
            'status' => 'Pending',
            'timestamp' => now(),
            'team' => 1,
            'lastreply' => '0',
        ]);
    }

    /**
     * The replies on a ticket, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function replies(int $ticketId): array
    {
        return $this->connection()
            ->table('cp_servicedeska')
            ->where('ticket_id', $ticketId)
            ->orderBy('timestamp')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->action_id,
                'author' => (string) $row->author,
                'from_staff' => (int) $row->isstaff !== 0,
                'text' => (string) $row->text,
                'action' => (string) $row->action,
                'at' => $this->iso($row->timestamp),
                /*
                 * The reply's IP is deliberately absent. It is recorded for an
                 * administrator reading the log browser, not for the other
                 * party to the conversation.
                 */
            ])
            ->all();
    }

    /**
     * Add a reply, and move the ticket's status.
     *
     * A player's reply reopens an answered ticket; a staff reply marks it
     * answered. That is what the legacy did, and it is what stops a ticket
     * somebody has replied to from sitting in "Answered" where nobody looks.
     */
    public function reply(
        int $ticketId,
        Account $author,
        string $text,
        bool $fromStaff,
        string $ip,
        ?string $action = null,
    ): void {
        $connection = $this->connection();

        $connection->transaction(function () use (
            $connection, $ticketId, $author, $text, $fromStaff, $ip, $action
        ): void {
            $connection->table('cp_servicedeska')->insert([
                'ticket_id' => $ticketId,
                'author' => $this->displayName($author),
                'text' => $text,
                'action' => (string) ($action ?? ''),
                'timestamp' => now(),
                'ip' => mb_substr($ip, 0, 39),
                'isstaff' => $fromStaff ? 1 : 0,
            ]);

            $connection->table('cp_servicedesk')
                ->where('ticket_id', $ticketId)
                // A closed ticket stays closed until somebody reopens it
                // explicitly, so a late reply does not resurrect it silently.
                ->where('status', '!=', 'Closed')
                ->update([
                    'status' => $fromStaff ? 'Answered' : 'In Progress',
                    'lastreply' => $this->displayName($author),
                ]);
        });
    }

    /**
     * Move a ticket's status.
     */
    public function setStatus(int $ticketId, string $status): bool
    {
        if (! in_array($status, self::STATUSES, true)) {
            return false;
        }

        return $this->connection()
            ->table('cp_servicedesk')
            ->where('ticket_id', $ticketId)
            ->update(['status' => $status]) > 0;
    }

    /**
     * A staff member's own support-desk preferences.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(Account $account): array
    {
        $row = $this->connection()
            ->table('cp_servicedesksettings')
            ->where('account_id', $account->account_id)
            ->first();

        return [
            'display_name' => (string) ($row->prefered_name ?? $account->userid),
            'team' => (int) ($row->team ?? 1),
            'email_alerts' => (int) ($row->emailalerts ?? 0) !== 0,
        ];
    }

    /**
     * @param  array{display_name?: string, team?: int, email_alerts?: bool}  $settings
     */
    public function saveSettings(Account $account, array $settings): void
    {
        $this->connection()->table('cp_servicedesksettings')->updateOrInsert(
            ['account_id' => $account->account_id],
            [
                'account_name' => $account->userid,
                'prefered_name' => (string) ($settings['display_name'] ?? $account->userid),
                'team' => (int) ($settings['team'] ?? 1),
                'emailalerts' => ($settings['email_alerts'] ?? false) ? 1 : 0,
                'timestamp' => now(),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    public function addCategory(string $name, bool $visible = true): int
    {
        return (int) $this->connection()->table('cp_servicedeskcat')->insertGetId([
            'name' => $name,
            'display' => $visible ? 1 : 0,
        ]);
    }

    public function updateCategory(int $categoryId, string $name, bool $visible): bool
    {
        return $this->connection()
            ->table('cp_servicedeskcat')
            ->where('cat_id', $categoryId)
            ->update(['name' => $name, 'display' => $visible ? 1 : 0]) > 0;
    }

    /**
     * Hide a category rather than delete it.
     *
     * Tickets reference a category by id, so removing the row would leave
     * historic tickets pointing at nothing. Hiding takes it out of the form
     * while the old tickets keep their label -- which is what `display` is for
     * and why the legacy had it.
     */
    public function hideCategory(int $categoryId): bool
    {
        return $this->connection()
            ->table('cp_servicedeskcat')
            ->where('cat_id', $categoryId)
            ->update(['display' => 0]) > 0;
    }

    /**
     * The name a staff member's replies are signed with.
     *
     * Their chosen display name if they set one, so staff are not required to
     * expose their account name to every player who opens a ticket.
     */
    public function displayName(Account $account): string
    {
        $preferred = $this->connection()
            ->table('cp_servicedesksettings')
            ->where('account_id', $account->account_id)
            ->value('prefered_name');

        $preferred = trim((string) $preferred);

        return $preferred !== '' ? $preferred : $account->userid;
    }

    public function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->servers->current()->loginConnection());
    }
}
