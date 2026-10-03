<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Actions\Auth\AuthenticateAccount;
use App\Models\Account;
use App\Support\Rathena\ServerGroup;
use App\Support\Tokens\SecureToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Registration e-mail confirmation.
 *
 * Ports modules/account/confirm.php and the confirmation half of create.php.
 *
 * An account awaiting confirmation is held in rAthena's state 5 -- the same
 * state as a permanent ban -- so it cannot sign in to the game or the panel
 * until the link is followed. That shared state is why
 * {@see AuthenticateAccount} looks for an unconfirmed
 * registration row before it reports a ban.
 *
 * ---------------------------------------------------------------------------
 * What changed from the legacy flow
 * ---------------------------------------------------------------------------
 *
 * 1. The token is a SHA-256 digest of 256 random bits, stored as a digest.
 *    The legacy code was `md5(rand())`, stored as itself, so the table was a
 *    list of working activation links.
 *
 * 2. The expiry is always set. The legacy UPDATE only wrote confirm_expire
 *    when EmailConfirmExpire was configured, but confirm.php looked up rows
 *    with `confirm_expire > NOW()` unconditionally -- and in SQL a comparison
 *    against NULL is never true. With that setting left at its default, every
 *    confirmation link failed and the account could never be activated.
 *
 * 3. The token is not written into the ban reason. The legacy panel put it
 *    there, which copied a working activation link into an audit table that
 *    the account admin screens display.
 *
 * 4. Resending issues a new token rather than re-sending the old one, so an
 *    expired request can be renewed. The legacy resend required
 *    `confirm_expire > NOW()`, meaning the one case where somebody needs a new
 *    link -- the old one lapsed -- was the case it refused.
 */
final readonly class AccountConfirmationService
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private AccountBanService $bans,
    ) {}

    /**
     * Hold a newly created account and issue its confirmation token.
     *
     * Call inside the same transaction as the account's creation. Between the
     * INSERT and this call the account is signable-in, and a registration that
     * failed halfway should not leave a live unconfirmed account behind.
     */
    public function issue(ServerGroup $group, Account $account): SecureToken
    {
        $token = SecureToken::generate();

        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->update([
                'confirmed' => 0,
                'confirm_code' => $token->digest,
                'confirm_expire' => $this->expiresAt(),
            ]);

        $this->bans->permanentlyBan(
            $group,
            $account,
            // No token in the reason. See the class comment.
            'Awaiting e-mail confirmation of the registration.',
        );

        return $token;
    }

    /**
     * Confirm a registration.
     *
     * @return Account|null The activated account, or null when the token does
     *                      not match a live unconfirmed registration. One null
     *                      covers "unknown", "expired" and "already used",
     *                      because the caller answers identically for all
     *                      three and distinguishing them tells the holder of a
     *                      stale link which case they have.
     */
    public function confirm(ServerGroup $group, string $presentedToken): ?Account
    {
        if (! SecureToken::looksValid($presentedToken)) {
            return null;
        }

        $connection = $this->connections->connection($group->loginConnection());

        $pending = $connection
            ->table('cp_createlog')
            ->where('confirm_code', SecureToken::digestOf($presentedToken))
            ->where('confirmed', 0)
            ->whereNotNull('confirm_expire')
            ->where('confirm_expire', '>', now())
            ->first();

        if ($pending === null) {
            return null;
        }

        $account = Account::query()->find($pending->account_id);

        if (! $account instanceof Account) {
            return null;
        }

        /*
         * Cleared, not just flagged. Leaving the digest in place would leave a
         * used token matchable, and the row's job is done once the account is
         * confirmed.
         */
        $connection
            ->table('cp_createlog')
            ->where('id', $pending->id)
            ->update([
                'confirmed' => 1,
                'confirm_code' => null,
                'confirm_expire' => null,
            ]);

        $this->bans->unban($group, $account, 'E-mail confirmation completed.');

        return $account;
    }

    /**
     * Issue a fresh token for an account still awaiting confirmation.
     *
     * Both the account name and the address on the account have to match,
     * which is what stops this being used to spray mail at an address from a
     * username alone.
     *
     * @return array{0: Account, 1: SecureToken}|null Null when nothing is
     *                                                awaiting confirmation for those details.
     */
    public function reissue(ServerGroup $group, string $username, string $email): ?array
    {
        $account = Account::query()
            ->matchingUserid($username)
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->first();

        if (! $account instanceof Account) {
            return null;
        }

        $awaiting = $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->where('confirmed', 0)
            ->exists();

        if (! $awaiting) {
            return null;
        }

        $token = SecureToken::generate();

        /*
         * A new token, which invalidates the previous one by overwriting it.
         * Only ever one live confirmation link per account.
         */
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->where('confirmed', 0)
            ->update([
                'confirm_code' => $token->digest,
                'confirm_expire' => $this->expiresAt(),
            ]);

        return [$account, $token];
    }

    private function expiresAt(): CarbonImmutable
    {
        $hours = max(1, (int) config('panel.registration.email_confirmation_expires_after_hours', 48));

        return now()->addHours($hours)->toImmutable();
    }
}
