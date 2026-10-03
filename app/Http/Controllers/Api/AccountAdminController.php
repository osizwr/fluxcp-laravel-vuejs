<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Editing an account as staff.
 *
 * Ports modules/account/edit.php.
 *
 * ---------------------------------------------------------------------------
 * What this will not change
 * ---------------------------------------------------------------------------
 *
 * The password. The legacy edit form did not offer it either, and it should
 * not: the account holder changes their own through the normal flow, and an
 * administrator who needs to help somebody locked out triggers a reset rather
 * than setting a credential they then know.
 *
 * ---------------------------------------------------------------------------
 * Rank protection
 * ---------------------------------------------------------------------------
 *
 * Staff cannot edit an account at or above their own level, and cannot promote
 * one past themselves. Without that, the lowest-ranked person with this screen
 * can promote themselves to administrator in two steps, which makes the whole
 * permission ladder decorative. FluxCP had the same idea as the `EditHigherPower`
 * ability, defaulted to NOONE.
 */
final class AccountAdminController
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Account $account): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof Account, 401);

        $this->assertMayEdit($actor, $account);

        $validated = $request->validate([
            'email' => ['sometimes', 'string', 'email', 'max:39'],
            'gender' => ['sometimes', 'string', 'in:M,F,S'],
            'group_id' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'birthdate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'state' => ['sometimes', 'integer', 'min:0'],
            /*
             * Shop credits, which the legacy edit form also carried. Guarded
             * by its own ability below, because adjusting a balance is giving
             * somebody money rather than correcting a detail.
             */
            'balance' => ['sometimes', 'integer', 'min:0'],
            // Not editable: userid, user_pass, logincount, lastlogin, last_ip.
            // The first two are credentials; the rest are facts the emulator
            // records, and rewriting them would be falsifying an audit trail.
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'Nothing to change.'], 422);
        }

        if (array_key_exists('group_id', $validated)) {
            $this->assertMayGrant($actor, (int) $validated['group_id']);
        }

        if (array_key_exists('balance', $validated)) {
            abort_unless(
                $actor->can('EditAccountBalance'),
                403,
                'You may not change account balances.',
            );

            $this->setBalance($account, (int) $validated['balance'], $actor);

            unset($validated['balance']);

            if ($validated === []) {
                return response()->json([
                    'message' => 'The account has been updated.',
                    'data' => ['changed' => ['balance']],
                ]);
            }
        }

        $changes = [];

        foreach ($validated as $field => $value) {
            $changes[$field === 'gender' ? 'sex' : $field] = $field === 'gender'
                ? Gender::from((string) $value)->value
                : $value;
        }

        $this->connections
            ->connection($this->servers->current()->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->update($changes);

        return response()->json([
            'message' => 'The account has been updated.',
            'data' => ['changed' => array_keys($changes)],
        ]);
    }

    /**
     * Set an account's credit balance, recording who did it.
     *
     * The adjustment goes into `cp_txnlog` alongside real payments, so the
     * account's donation history shows it with the name of whoever made it.
     * A balance that changes with no record is the thing an operator cannot
     * answer a question about later.
     */
    private function setBalance(Account $account, int $balance, Account $actor): void
    {
        $connection = $this->connections->connection(
            $this->servers->current()->loginConnection(),
        );

        $current = (int) ($connection->table('cp_credits')
            ->where('account_id', $account->account_id)
            ->value('balance') ?? 0);

        if ($current === $balance) {
            return;
        }

        $connection->transaction(function () use ($connection, $account, $balance, $current, $actor): void {
            $connection->table('cp_credits')->updateOrInsert(
                ['account_id' => $account->account_id],
                ['balance' => $balance],
            );

            $connection->table('cp_txnlog')->insert([
                'account_id' => $account->account_id,
                'credits' => $balance - $current,
                'payment_status' => 'Completed',
                'txn_id' => 'manual-'.bin2hex(random_bytes(8)),
                'txn_type' => 'manual_adjustment',
                'mc_gross' => '0.00',
                'mc_currency' => (string) config('panel.donations.currency', 'USD'),
                'item_name' => sprintf(
                    'Balance set to %d by %s (was %d)',
                    $balance,
                    $actor->userid,
                    $current,
                ),
                'process_date' => now(),
            ]);
        });
    }

    /**
     * Staff may not edit an account at or above their own rank.
     */
    private function assertMayEdit(Account $actor, Account $subject): void
    {
        if ($actor->account_id === $subject->account_id) {
            // Editing your own account through the admin screen is refused
            // rather than allowed: the normal account pages are the route for
            // that, and they ask for the password.
            abort(403, 'Use your own account settings to change your details.');
        }

        abort_unless(
            $actor->outranks($subject) || $actor->can('EditHigherPower'),
            403,
            'You may not edit an account of that rank.',
        );
    }

    /**
     * Staff may not grant a rank they do not themselves exceed.
     */
    private function assertMayGrant(Account $actor, int $groupId): void
    {
        abort_unless($actor->can('EditAccountGroupID'), 403, 'You may not change account ranks.');

        $granted = (array) config("permissions.account_groups.{$groupId}", []);
        $level = $granted['level'] ?? null;

        if ($level === null) {
            // An unmapped group id is treated as a plain player everywhere
            // else, so granting it is harmless.
            return;
        }

        abort_unless(
            $actor->accountLevel()->value > $level->value || $actor->can('EditHigherPower'),
            403,
            'You may not grant a rank at or above your own.',
        );
    }
}
