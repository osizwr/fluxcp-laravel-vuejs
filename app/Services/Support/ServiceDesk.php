<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Account;
use App\Support\Rathena\LocalTransactionId;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
     * Stored as strings rather than as an enum column, so an existing FluxCP
     * install's tickets keep the state they are in and the old panel can still
     * read the table.
     *
     * The legacy wrote three of these: `Pending` when a ticket is opened,
     * `Resolved` when staff answer it, and `Closed`. An earlier revision of
     * this port listed `In Progress` and `Answered` -- which the legacy never
     * wrote -- and omitted `Resolved`, so a migrated install had tickets in a
     * state this code could neither set nor recognise.
     *
     * The two additions are kept because they are useful and are not a
     * replacement for anything: the legacy tracked whether a ticket was
     * awaiting a player or awaiting staff through its `lastreply` column
     * rather than its status, and these say it directly.
     */
    public const STATUSES = ['Pending', 'In Progress', 'Answered', 'Resolved', 'Closed'];

    /**
     * The statuses that keep a ticket in the queue.
     *
     * Everything but `Closed`, which is the legacy's own test -- its queue
     * queries all read `status != 'Closed'`. A resolved ticket stays listed on
     * purpose, so a player who is not satisfied can reply to it rather than
     * open a second one.
     */
    public const OPEN_STATUSES = ['Pending', 'In Progress', 'Answered', 'Resolved'];

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
     * Award credits to a ticket's owner.
     *
     * Ports the credit award on modules/servicedesk/staffview.php's resolve
     * action, with two changes.
     *
     * The credited account is the ticket's owner, read from the ticket. The
     * legacy credited `$_POST['account_id']`, so the account to be paid was
     * whatever the submitted form said -- and the form was rendered for staff,
     * who could change it. Nothing about a reward needs to be taken on trust
     * from a request when the ticket already says whose it is.
     *
     * The amount is capped by configuration. The legacy took `intval($_POST)`
     * with no ceiling, so a mistyped figure awarded a fortune and the only
     * record of it was a line of free text.
     *
     * Returns the credited amount, which is zero when rewards are turned off.
     *
     * @throws InvalidArgumentException when the amount exceeds the cap.
     */
    public function awardCredits(int $ticketId, Account $staff, int $credits): int
    {
        if ($credits <= 0 || config('panel.service_desk.credit_rewards.enabled') !== true) {
            return 0;
        }

        $maximum = max(0, (int) config('panel.service_desk.credit_rewards.maximum', 500));

        if ($credits > $maximum) {
            throw new InvalidArgumentException(
                "A single award may not exceed {$maximum} credits."
            );
        }

        $ticket = $this->find($ticketId);

        if ($ticket === null) {
            return 0;
        }

        $accountId = (int) ($ticket->account_id ?? 0);

        if ($accountId <= 0) {
            return 0;
        }

        $connection = $this->connection();

        $connection->transaction(function () use ($connection, $accountId, $credits, $ticketId, $staff): void {
            /*
             * Added to whatever is there rather than set, and in SQL rather
             * than read-then-write, so two awards in the same moment cannot
             * lose one of them.
             */
            $updated = $connection->table('cp_credits')
                ->where('account_id', $accountId)
                ->update(['balance' => $connection->raw("balance + {$credits}")]);

            if ($updated === 0) {
                $connection->table('cp_credits')->insert([
                    'account_id' => $accountId,
                    'balance' => $credits,
                ]);
            }

            /*
             * Logged where every other credit movement is logged, so an
             * operator asking "where did these credits come from" has one
             * place to look rather than two.
             */
            $connection->table('cp_txnlog')->insert([
                'account_id' => $accountId,
                'credits' => $credits,
                'payment_status' => 'Completed',
                'txn_id' => LocalTransactionId::for('sd'),
                'txn_type' => 'service_desk_reward',
                'mc_gross' => '0.00',
                'mc_currency' => (string) config('panel.donations.currency', 'USD'),
                'item_name' => sprintf(
                    'Ticket #%d resolved, %d credits awarded by %s',
                    $ticketId,
                    $credits,
                    $staff->userid,
                ),
                'process_date' => now(),
            ]);
        });

        return $credits;
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
