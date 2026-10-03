<?php

declare(strict_types=1);

namespace App\Services\Donations;

use App\Models\Account;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turning a confirmed payment into credits.
 *
 * Ports Flux_PaymentNotifyRequest and the bodies of modules/donate/*.
 *
 * ---------------------------------------------------------------------------
 * What makes this different from the rest of the panel
 * ---------------------------------------------------------------------------
 *
 * Every other write here can be undone. Crediting an account cannot be, in
 * practice: the player spends the credits, the items are delivered in game,
 * and taking them back means a support conversation. So the checks are
 * deliberately unforgiving and every refusal is logged with its reason.
 *
 * Four things must all hold before anything is credited:
 *
 *   1. The notification is genuine, confirmed by posting it back to the
 *      provider and getting VERIFIED.
 *   2. The payment is Completed, not Pending or Refunded.
 *   3. The money arrived at an address this server owns, in the expected
 *      currency.
 *   4. The transaction has not been processed before.
 *
 * The fourth is the one that is easy to leave out and the most expensive to
 * get wrong: providers resend notifications, by design, until they are
 * acknowledged. Without an idempotency check, a resend is free credits.
 */
final readonly class DonationService
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
    ) {}

    /**
     * How many credits a payment is worth.
     *
     * Rounded down. A rate that produces 4.9 credits gives 4, because handing
     * out a credit nobody paid for is the error worth avoiding.
     */
    public function creditsFor(float $amount): int
    {
        $rate = (float) config('panel.donations.credits_per_unit', 1.0);

        return max(0, (int) floor($amount * $rate));
    }

    /**
     * Whether this transaction has already been recorded.
     *
     * The guard against a resent or replayed notification.
     */
    public function alreadyProcessed(string $transactionId, ?ServerGroup $group = null): bool
    {
        if (trim($transactionId) === '') {
            return false;
        }

        return $this->connection($group)
            ->table('cp_txnlog')
            ->where('txn_id', $transactionId)
            ->exists();
    }

    /**
     * Record a payment, crediting it now or holding it.
     *
     * ---------------------------------------------------------------------
     * The hold queue
     * ---------------------------------------------------------------------
     *
     * A payment from an address this server has not seen before is recorded
     * with a `hold_until`, and the credits are not added yet. If the payment
     * is reversed inside that window -- which is what a stolen card looks like
     * -- the hold is cancelled and nothing was ever spendable. If the window
     * passes without a reversal, `panel:release-held-credits` adds the credits
     * and marks the payer trusted, so their next donation is immediate.
     *
     * This is FluxCP's design and it is worth keeping: without it, a
     * chargeback leaves the server having delivered items for money it no
     * longer has, and no way to take them back.
     *
     * @param  array<string, mixed>  $payment
     * @return array{credits: int, held: bool}
     */
    public function record(
        ?Account $account,
        array $payment,
        bool $credit,
        ?ServerGroup $group = null,
    ): array {
        $group ??= $this->servers->current();
        $connection = $this->connection($group);

        $credits = $credit && $account !== null
            ? $this->creditsFor((float) ($payment['mc_gross'] ?? 0))
            : 0;

        $payerEmail = (string) ($payment['payer_email'] ?? '');

        $hold = $credits > 0
            && (int) config('panel.donations.hold_hours', 0) > 0
            && ! $this->isTrusted($payerEmail, $group);

        $holdUntil = $hold
            ? now()->addHours((int) config('panel.donations.hold_hours'))
            : null;

        $connection->transaction(function () use (
            $connection, $account, $payment, $credits, $group, $holdUntil
        ): void {
            $connection->table('cp_txnlog')->insert([
                'account_id' => $account?->account_id ?? 0,
                'server_name' => $group->name,
                'credits' => $credits,
                'receiver_email' => $payment['receiver_email'] ?? null,
                'item_name' => $payment['item_name'] ?? null,
                'payment_status' => $payment['payment_status'] ?? null,
                'pending_reason' => $payment['pending_reason'] ?? null,
                'payment_date' => $payment['payment_date'] ?? null,
                'mc_gross' => $payment['mc_gross'] ?? null,
                'mc_fee' => $payment['mc_fee'] ?? null,
                'mc_currency' => $payment['mc_currency'] ?? null,
                'txn_id' => $payment['txn_id'] ?? null,
                'txn_type' => $payment['txn_type'] ?? null,
                'parent_txn_id' => $payment['parent_txn_id'] ?? null,
                'payer_email' => $payment['payer_email'] ?? null,
                'process_date' => now(),
                'hold_until' => $holdUntil,
            ]);

            // Credited now only when it is not being held.
            if ($credits > 0 && $account !== null && $holdUntil === null) {
                $this->addCredits($account, $credits, $group);
            }
        });

        return ['credits' => $credits, 'held' => $holdUntil !== null];
    }

    /*
    |--------------------------------------------------------------------------
    | The hold queue
    |--------------------------------------------------------------------------
    */

    /**
     * Release or cancel held payments whose window has passed.
     *
     * Ports Flux::processHeldCredits(), which the legacy ran from an HTTP
     * endpoint guarded by comparing a query parameter against the installer
     * password. It is a scheduled command here.
     *
     * @return array{released: int, cancelled: int, credits: int}
     */
    public function processHeldPayments(?ServerGroup $group = null): array
    {
        $group ??= $this->servers->current();
        $connection = $this->connection($group);

        $held = $connection->table('cp_txnlog')
            ->select('id', 'account_id', 'payer_email', 'credits', 'txn_id', 'hold_until')
            ->where('account_id', '>', 0)
            ->whereNotNull('hold_until')
            ->where('payment_status', 'Completed')
            ->get();

        $released = 0;
        $cancelled = 0;
        $creditsAdded = 0;

        foreach ($held as $payment) {
            /*
             * A later row reversing this one. This is the case the hold exists
             * for: the payment completed, the hold window had not passed, and
             * then the money went back.
             */
            $reversed = $connection->table('cp_txnlog')
                ->whereIn('payment_status', ['Cancelled_Reversed', 'Reversed', 'Refunded'])
                ->where('parent_txn_id', $payment->txn_id)
                ->exists();

            if ($reversed) {
                $connection->table('cp_txnlog')
                    ->where('id', $payment->id)
                    ->update(['credits' => 0, 'hold_until' => null]);

                $cancelled++;

                continue;
            }

            if ($payment->hold_until === null
                || Carbon::parse((string) $payment->hold_until)->isFuture()) {
                continue;
            }

            $account = Account::query()->find($payment->account_id);

            if (! $account instanceof Account) {
                continue;
            }

            $connection->transaction(function () use (
                $connection, $payment, $account, $group, &$creditsAdded
            ): void {
                $this->addCredits($account, (int) $payment->credits, $group);

                $connection->table('cp_txnlog')
                    ->where('id', $payment->id)
                    ->update(['hold_until' => null]);

                /*
                 * The payer is trusted from now on, so their next donation is
                 * immediate. This is what populates cp_trusted -- it is not a
                 * list an administrator maintains by hand.
                 */
                $email = (string) ($payment->payer_email ?? '');

                if ($email !== '' && ! $this->isTrusted($email, $group)) {
                    $this->trust($account, $email, $group);
                }

                $creditsAdded += (int) $payment->credits;
            });

            $released++;
        }

        return ['released' => $released, 'cancelled' => $cancelled, 'credits' => $creditsAdded];
    }

    /**
     * How many payments are on hold, for the dry run.
     */
    public function heldPaymentCount(?ServerGroup $group = null): int
    {
        return $this->connection($group)
            ->table('cp_txnlog')
            ->where('account_id', '>', 0)
            ->whereNotNull('hold_until')
            ->where('payment_status', 'Completed')
            ->count();
    }

    /**
     * Payments currently on hold for an account.
     */
    public function heldFor(Account $account, ?ServerGroup $group = null): Builder
    {
        return $this->connection($group)
            ->table('cp_txnlog')
            ->select('txn_id', 'credits', 'mc_gross', 'mc_currency', 'hold_until')
            ->where('account_id', $account->account_id)
            ->whereNotNull('hold_until');
    }

    /**
     * Add credits to an account, creating its balance row if it has none.
     *
     * The row is created on first donation rather than with the account, which
     * is why this is an upsert and why the shop treats a missing row as zero.
     */
    public function addCredits(Account $account, int $credits, ?ServerGroup $group = null): void
    {
        $connection = $this->connection($group);

        $existing = $connection->table('cp_credits')
            ->where('account_id', $account->account_id)
            ->exists();

        if ($existing) {
            $connection->table('cp_credits')
                ->where('account_id', $account->account_id)
                // Incremented in SQL rather than read-then-written, so two
                // payments arriving together cannot lose one.
                ->update([
                    'balance' => $connection->raw("balance + {$credits}"),
                    'last_donation_date' => now(),
                    'last_donation_amount' => $credits,
                ]);

            return;
        }

        $connection->table('cp_credits')->insert([
            'account_id' => $account->account_id,
            'balance' => $credits,
            'last_donation_date' => now(),
            'last_donation_amount' => $credits,
        ]);
    }

    /**
     * An account's own donation history.
     */
    public function historyFor(Account $account, ?ServerGroup $group = null): Builder
    {
        return $this->connection($group)
            ->table('cp_txnlog')
            ->select('txn_id', 'mc_gross', 'mc_currency', 'credits', 'payment_status', 'process_date')
            ->where('account_id', $account->account_id);
    }

    /**
     * Whether a payer's address is trusted to skip the pending hold.
     */
    public function isTrusted(string $email, ?ServerGroup $group = null): bool
    {
        if (trim($email) === '') {
            return false;
        }

        return $this->connection($group)
            ->table('cp_trusted')
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->whereNull('delete_date')
            ->exists();
    }

    public function trust(Account $account, string $email, ?ServerGroup $group = null): void
    {
        $this->connection($group)->table('cp_trusted')->insert([
            'account_id' => $account->account_id,
            'email' => $email,
            'create_date' => now(),
        ]);
    }

    public function untrust(int $id, ?ServerGroup $group = null): bool
    {
        // Marked rather than deleted, so the record that it was once trusted
        // survives.
        return $this->connection($group)
            ->table('cp_trusted')
            ->where('id', $id)
            ->whereNull('delete_date')
            ->update(['delete_date' => now()]) > 0;
    }

    /**
     * An account's own trusted payer addresses.
     *
     * The legacy `donate/trusted` action was the player's own list, scoped to
     * their session -- not an administrator's screen. It is populated
     * automatically when a held payment is released, so it is a record of
     * which of their addresses have cleared rather than something anybody
     * edits.
     */
    public function trustedFor(Account $account, ?ServerGroup $group = null): Builder
    {
        return $this->connection($group)
            ->table('cp_trusted')
            ->select('id', 'account_id', 'email', 'create_date')
            ->where('account_id', $account->account_id)
            ->whereNull('delete_date');
    }

    /**
     * Record a notification that was refused, and why.
     *
     * Logged rather than discarded: a genuine payment that this rejected is a
     * player who paid and got nothing, and the only way to find out is to have
     * written down why.
     *
     * @param  array<string, mixed>  $payment
     */
    public function refuse(string $reason, array $payment): void
    {
        Log::warning('A payment notification was refused.', [
            'reason' => $reason,
            'txn_id' => $payment['txn_id'] ?? null,
            'payment_status' => $payment['payment_status'] ?? null,
            'receiver_email' => $payment['receiver_email'] ?? null,
            'mc_gross' => $payment['mc_gross'] ?? null,
            'mc_currency' => $payment['mc_currency'] ?? null,
            // The payer's address is deliberately not logged: a refused
            // notification may be forged, and this file is read by people who
            // do not need a stranger's e-mail address.
        ]);
    }

    private function connection(?ServerGroup $group): ConnectionInterface
    {
        $group ??= $this->servers->current();

        return $this->connections->connection($group->loginConnection());
    }
}
