<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Account;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Other browsers signed in as the same account.
 *
 * Used after a password change, so that changing the password actually ends
 * somebody else's access rather than merely stopping them signing in again.
 * Without this, an attacker who had already signed in keeps their session for
 * as long as it lives, and the account holder's password change achieves
 * nothing against the one case it is most often a response to.
 *
 * The legacy panel handled this by logging the account holder out of their own
 * session and leaving every other session alone -- the opposite of what is
 * wanted. See docs/MIGRATION_DECISIONS.md (D18).
 *
 * This only works with the `database` session driver, which is the default.
 * With `file` or `cookie` there is nothing to query, so the method reports
 * that it could not do anything rather than pretending it did.
 */
final readonly class SessionRegistry
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    /**
     * End every session for this account except the one given.
     *
     * @param  string|null  $exceptSessionId  The session to keep, normally the
     *                                        current one.
     * @return bool Whether the sessions could be revoked. False means the
     *              store cannot be queried, not that there were none.
     */
    public function revokeOtherSessions(Account $account, ?string $exceptSessionId): bool
    {
        if (config('session.driver') !== 'database') {
            return false;
        }

        try {
            $query = $this->connections
                ->connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $account->account_id);

            if ($exceptSessionId !== null && $exceptSessionId !== '') {
                $query->where('id', '!=', $exceptSessionId);
            }

            $query->delete();

            return true;
        } catch (Throwable $e) {
            /*
             * Logged and reported rather than thrown. The password change it
             * follows has already succeeded, and failing the request now would
             * tell the account holder their password did not change when it
             * did.
             */
            Log::warning('Other sessions for an account could not be revoked.', [
                'account_id' => $account->account_id,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
