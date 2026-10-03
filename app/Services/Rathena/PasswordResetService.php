<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Enums\Gender;
use App\Models\Account;
use App\Support\Rathena\ServerGroup;
use App\Support\Tokens\SecureToken;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Validation\ValidationException;

/**
 * Password reset by e-mail.
 *
 * Ports modules/account/resetpass.php (request) and resetpw.php (completion).
 *
 * ---------------------------------------------------------------------------
 * Five things the legacy flow did that this does not
 * ---------------------------------------------------------------------------
 *
 * 1. It generated a password and e-mailed it in cleartext. The account's
 *    credential then existed as readable text in a mailbox, in the sending
 *    server's queue and in every relay between them, and it was a password the
 *    account holder had not chosen. Here the link lets somebody choose their
 *    own, and no password is ever put in an e-mail. (D16)
 *
 * 2. It wrote the account's current password into cp_resetpass.old_password and
 *    the new one into new_password. Those columns still exist, for an existing
 *    FluxCP install reading the same table, and are written empty. (D2)
 *
 * 3. Its token was `md5(rand() + $account_id)`. `rand()` is not a
 *    cryptographic generator, the account id is public, and the result was
 *    stored as itself -- so the table was a list of working reset links, and a
 *    token was guessable from a few samples. (D15)
 *
 * 4. It never expired a token. `request_date` was written and then never read
 *    by resetpw.php, so a link from a mailbox compromised years later still
 *    worked.
 *
 * 5. It keyed the completion step on `account_id` taken from the URL as well
 *    as the code. That added nothing -- the code alone had to match the same
 *    row -- but it put the account id in the link. Here the token is the only
 *    thing in the URL, because it is the only thing that is a secret.
 *
 * What is preserved deliberately: the account must be in state 0 with a
 * playable `sex`, and staff above the configured level cannot reset by e-mail
 * at all (the legacy NoResetPassGroupLevel setting). Holding a game master's
 * mailbox should not be enough to take their account.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private RathenaAccountService $accounts,
    ) {}

    /**
     * Start a reset.
     *
     * @return array{0: Account, 1: SecureToken}|null Null when the details do
     *                                                not identify an account that may be reset. The caller answers
     *                                                identically either way -- see the note in lang/en/accounts.php.
     */
    public function request(
        ServerGroup $group,
        string $username,
        string $email,
        string $requestedFromIp,
    ): ?array {
        $account = Account::query()
            ->matchingUserid($username)
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            // state 0 only: a banned account is not recovered by resetting its
            // password, and an account still awaiting confirmation should
            // follow its confirmation link instead.
            ->where('state', 0)
            // Excludes rAthena's server accounts, which have no human owner
            // and no mailbox to send to.
            ->whereIn('sex', [Gender::Male->value, Gender::Female->value])
            ->first();

        if (! $account instanceof Account) {
            return null;
        }

        if ($this->isProtectedFromEmailReset($account)) {
            return null;
        }

        $token = SecureToken::generate();

        $connection = $this->connections->connection($group->loginConnection());

        /*
         * Any earlier outstanding request is retired first, so an account only
         * ever has one live reset link. Without this, every request ever made
         * stays usable and asking twice doubles the number of ways in.
         */
        $connection
            ->table('cp_resetpass')
            ->where('account_id', $account->account_id)
            ->where('reset_done', 0)
            ->update([
                'reset_done' => 1,
                'reset_date' => now(),
                'reset_ip' => mb_substr($requestedFromIp, 0, 39),
            ]);

        $connection->table('cp_resetpass')->insert([
            'code' => $token->digest,
            'account_id' => $account->account_id,
            // Empty, not the account's password. See point 2 above.
            'old_password' => '',
            'new_password' => '',
            'request_date' => now(),
            'request_ip' => mb_substr($requestedFromIp, 0, 39),
            'reset_done' => 0,
        ]);

        return [$account, $token];
    }

    /**
     * Complete a reset with a password the account holder chose.
     *
     * @return Account|null Null when the token does not match a live request.
     *
     * @throws ValidationException When the chosen password fails the policy.
     */
    public function reset(
        ServerGroup $group,
        string $presentedToken,
        string $newPassword,
        string $resetFromIp,
    ): ?Account {
        if (! SecureToken::looksValid($presentedToken)) {
            return null;
        }

        $connection = $this->connections->connection($group->loginConnection());

        $request = $connection
            ->table('cp_resetpass')
            ->where('code', SecureToken::digestOf($presentedToken))
            ->where('reset_done', 0)
            ->where('request_date', '>', now()->subHours($this->expiryHours()))
            ->first();

        if ($request === null) {
            return null;
        }

        $account = Account::query()->find($request->account_id);

        if (! $account instanceof Account) {
            return null;
        }

        /*
         * Re-checked at completion, not only at request time. A ban or a
         * promotion to staff between the two steps must take effect, or an
         * outstanding link outlives the restriction that would have refused it.
         */
        if ($account->state !== 0 || $this->isProtectedFromEmailReset($account)) {
            return null;
        }

        /*
         * The password is validated before the token is retired, so a rejected
         * password leaves the link usable and the person can try again rather
         * than having to request a new one.
         */
        $this->accounts->changePassword($account, $newPassword, $resetFromIp);

        $connection
            ->table('cp_resetpass')
            ->where('id', $request->id)
            ->update([
                'reset_done' => 1,
                'reset_date' => now(),
                'reset_ip' => mb_substr($resetFromIp, 0, 39),
                // Still empty. See point 2 above.
                'new_password' => '',
            ]);

        return $account;
    }

    /**
     * Whether this account is one that may not be reset by e-mail.
     *
     * The legacy NoResetPassGroupLevel setting. Note that the endpoint does not
     * report this: it answers as it does for an unknown account, so the form
     * cannot be used to find out which accounts belong to staff.
     */
    public function isProtectedFromEmailReset(Account $account): bool
    {
        $blockedAt = config('panel.password_reset.blocked_at_or_above_level');

        if ($blockedAt === null) {
            return false;
        }

        return $account->accountLevel()->value >= (int) $blockedAt;
    }

    private function expiryHours(): int
    {
        return max(1, (int) config('panel.password_reset.expires_after_hours', 2));
    }
}
