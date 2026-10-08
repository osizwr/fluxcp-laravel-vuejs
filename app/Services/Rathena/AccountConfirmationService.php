<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Actions\Auth\AuthenticateAccount;
use App\Enums\LoginFailure;
use App\Models\Account;
use App\Support\Accounts\PendingConfirmationSession;
use App\Support\Rathena\ServerGroup;
use App\Support\Tokens\ConfirmationSecrets;
use App\Support\Tokens\OneTimeCode;
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
    public function issue(ServerGroup $group, Account $account): ConfirmationSecrets
    {
        $token = SecureToken::generate();
        $code = OneTimeCode::generate();
        $expires = $this->expiresAt();

        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->update([
                'confirmed' => 0,
                'confirm_code' => $token->digest,
                'confirm_expire' => $expires,
            ]);

        /*
         * The lock goes on before the code is stored, and the order is not
         * cosmetic.
         *
         * This ban is the only thing stopping the account signing in to the
         * game: everything else here is bookkeeping. Anything that runs before
         * it and throws leaves a live, unconfirmed, fully usable account --
         * which is precisely the failure the whole flow exists to prevent, and
         * exactly what happened when the code table was missing on a database
         * that had not had `panel:install-schema` run against it.
         *
         * Reversed, the worst failure is a locked account with no code, which
         * a visitor fixes by asking for another one.
         */
        $this->bans->permanentlyBan(
            $group,
            $account,
            // No token in the reason. See the class comment.
            'Awaiting e-mail confirmation of the registration.',
        );

        $this->storeCode($group, $account, $code, $expires);

        return new ConfirmationSecrets($token, $code);
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

        $this->clearCode($group, $account);

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
     * @return array{0: Account, 1: ConfirmationSecrets}|null Null when nothing
     *                                                        is awaiting confirmation for those details.
     */
    public function reissue(ServerGroup $group, string $username, string $email): ?array
    {
        $account = Account::query()
            ->matchingUserid($username)
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->first();

        return $account instanceof Account ? $this->reissueFor($group, $account) : null;
    }

    /**
     * The same, for a caller that has already established who it is talking
     * to.
     *
     * Takes the address from the account rather than being told it, which is
     * the point: the one caller is the resend behind a sign-in attempt that
     * got as far as {@see LoginFailure::PendingConfirmation}, where the
     * password has already been checked and quoting the address back would add
     * nothing. See {@see PendingConfirmationSession} for why that is enough.
     *
     * Do not call this from anywhere that has not established ownership. On an
     * account name alone it would mail a working activation link to whoever
     * owns the name, which is exactly what {@see reissue()} exists to prevent.
     *
     * @return array{0: Account, 1: ConfirmationSecrets}|null Null when nothing
     *                                                        is awaiting confirmation for that account.
     */
    public function reissueForVerifiedOwner(ServerGroup $group, string $username): ?array
    {
        $account = Account::query()->matchingUserid($username)->first();

        return $account instanceof Account ? $this->reissueFor($group, $account) : null;
    }

    /**
     * @return array{0: Account, 1: ConfirmationSecrets}|null
     */
    private function reissueFor(ServerGroup $group, Account $account): ?array
    {
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
        $code = OneTimeCode::generate();
        $expires = $this->expiresAt();

        /*
         * A new token, which invalidates the previous one by overwriting it.
         * Only ever one live confirmation link per account -- and, below, only
         * ever one live code, which is also what resets a spent attempt count.
         */
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->where('confirmed', 0)
            ->update([
                'confirm_code' => $token->digest,
                'confirm_expire' => $expires,
            ]);

        $this->storeCode($group, $account, $code, $expires);

        return [$account, new ConfirmationSecrets($token, $code)];
    }

    /**
     * How many wrong codes an account tolerates before the code is burned.
     *
     * Six digits is a million possibilities, which sounds safe and is not: an
     * unattended script works through a meaningful fraction of it quickly. The
     * ceiling is what makes the code safe, so it is deliberately low -- a
     * person mistyping a six-digit code five times has a different problem
     * that a fresh code solves.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Confirm a registration from the typed code.
     *
     * Scoped to one account on purpose. A code alone is not a credential: six
     * digits across every pending registration would be guessable in bulk,
     * where six digits against one named account is one in a million with five
     * tries.
     *
     * @return Account|null Null for an unknown account, a wrong code, an
     *                      expired one, and one that has been guessed at too
     *                      often. The caller answers identically for all four,
     *                      because telling them apart tells somebody probing
     *                      which case they are in.
     */
    public function confirmByCode(ServerGroup $group, string $username, string $presentedCode): ?Account
    {
        if (! OneTimeCode::looksValid($presentedCode)) {
            return null;
        }

        $account = Account::query()->matchingUserid($username)->first();

        if (! $account instanceof Account) {
            return null;
        }

        $connection = $this->connections->connection($group->loginConnection());

        $pending = $connection
            ->table('cp_registration_otp')
            ->where('account_id', $account->account_id)
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->first();

        if ($pending === null) {
            return null;
        }

        if (! hash_equals((string) $pending->code, OneTimeCode::digestOf($presentedCode))) {
            /*
             * Counted before the answer is returned, so a wrong guess costs
             * the guesser an attempt whether or not they wait for the reply.
             */
            $connection
                ->table('cp_registration_otp')
                ->where('account_id', $account->account_id)
                ->increment('attempts');

            return null;
        }

        /*
         * The registration row still has to be live. Without this a code could
         * activate an account whose link had already been used, or one whose
         * registration had been expired by other means.
         */
        $registration = $connection
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->where('confirmed', 0)
            ->first();

        if ($registration === null) {
            return null;
        }

        $connection
            ->table('cp_createlog')
            ->where('id', $registration->id)
            ->update([
                'confirmed' => 1,
                'confirm_code' => null,
                'confirm_expire' => null,
            ]);

        $this->clearCode($group, $account);

        $this->bans->unban($group, $account, 'E-mail confirmation completed.');

        return $account;
    }

    /**
     * Replace whatever code the account had.
     *
     * An upsert rather than an insert: only one code is ever live, and issuing
     * a new one has to reset the attempt count or a visitor who mistyped five
     * times could never recover by asking for another.
     */
    private function storeCode(
        ServerGroup $group,
        Account $account,
        OneTimeCode $code,
        CarbonImmutable $expires,
    ): void {
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_registration_otp')
            ->updateOrInsert(
                ['account_id' => $account->account_id],
                ['code' => $code->digest, 'expires_at' => $expires, 'attempts' => 0],
            );
    }

    /** Dropped once the account is confirmed, by either route. */
    private function clearCode(ServerGroup $group, Account $account): void
    {
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_registration_otp')
            ->where('account_id', $account->account_id)
            ->delete();
    }

    private function expiresAt(): CarbonImmutable
    {
        $hours = max(1, (int) config('panel.registration.email_confirmation_expires_after_hours', 48));

        return now()->addHours($hours)->toImmutable();
    }
}
