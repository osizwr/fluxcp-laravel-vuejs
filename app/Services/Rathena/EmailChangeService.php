<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Models\Account;
use App\Support\Rathena\ServerGroup;
use App\Support\Tokens\SecureToken;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changing the e-mail address on an account.
 *
 * Ports modules/account/changemail.php and confirmemail.php.
 *
 * With confirmation required -- the default, and the legacy
 * RequireChangeConfirm setting -- the address on the account is not touched
 * until the proposed one has been proven reachable. That ordering matters: the
 * e-mail address is what password reset trusts, so an attacker on a hijacked
 * session who could change it immediately would own the account permanently.
 * Making them prove they can read the new address first does not stop that, but
 * it does mean the change leaves a trail and the real owner's address keeps
 * working until it happens.
 *
 * Differences from the legacy flow:
 *
 *   - The token is generated and stored as in {@see SecureToken}, rather than
 *     `md5(rand() + $account_id)` stored as itself. (D15)
 *
 *   - Requests expire. confirmemail.php looked up cp_emailchange by `code` and
 *     `change_done` only, so request_date was written and never read and a
 *     link stayed valid indefinitely.
 *
 *   - An earlier outstanding request is retired when a new one is made, so
 *     only the most recently requested address can be confirmed. The legacy
 *     table accumulated live requests, and any of them could still be
 *     confirmed later.
 */
final readonly class EmailChangeService
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    /**
     * Request a change.
     *
     * @return SecureToken|null The token to e-mail to the proposed address, or
     *                          null when confirmation is not required and the
     *                          change has already been applied.
     *
     * @throws ValidationException
     */
    public function request(
        ServerGroup $group,
        Account $account,
        string $newEmail,
        string $requestedFromIp,
    ): ?SecureToken {
        $this->validate($group, $account, $newEmail);

        $connection = $this->connections->connection($group->loginConnection());

        if (config('panel.email_change.require_confirmation') !== true) {
            $this->apply($group, $account, $newEmail, $requestedFromIp, null);

            return null;
        }

        $token = SecureToken::generate();

        $connection
            ->table('cp_emailchange')
            ->where('account_id', $account->account_id)
            ->where('change_done', 0)
            ->update([
                'change_done' => 1,
                'change_date' => now(),
                'change_ip' => mb_substr($requestedFromIp, 0, 39),
            ]);

        $connection->table('cp_emailchange')->insert([
            'code' => $token->digest,
            'account_id' => $account->account_id,
            'old_email' => $account->email,
            'new_email' => $newEmail,
            'request_date' => now(),
            'request_ip' => mb_substr($requestedFromIp, 0, 39),
            'change_done' => 0,
        ]);

        return $token;
    }

    /**
     * Confirm a requested change.
     *
     * The account is passed in rather than looked up from the token, because
     * this endpoint requires a session: the person following the link has to be
     * signed in as the account the request belongs to. That is the legacy
     * behaviour from confirmemail.php and worth keeping -- it means a token
     * read out of somebody's mailbox is not on its own enough to move their
     * account's address.
     *
     * @return string|null The address now on the account, or null when the
     *                     token does not match a live request for it.
     */
    public function confirm(
        ServerGroup $group,
        Account $account,
        string $presentedToken,
        string $confirmedFromIp,
    ): ?string {
        if (! SecureToken::looksValid($presentedToken)) {
            return null;
        }

        $request = $this->connections
            ->connection($group->loginConnection())
            ->table('cp_emailchange')
            ->where('code', SecureToken::digestOf($presentedToken))
            ->where('account_id', $account->account_id)
            ->where('change_done', 0)
            ->where('request_date', '>', now()->subHours($this->expiryHours()))
            ->first();

        if ($request === null || ! is_string($request->new_email) || $request->new_email === '') {
            return null;
        }

        /*
         * Re-checked at confirmation. Between the request and the link being
         * followed somebody else may have taken the address, and applying it
         * anyway would put a duplicate in a column the operator asked to keep
         * unique.
         */
        if ($this->emailTaken($group, $request->new_email, $account->account_id)) {
            return null;
        }

        $this->apply($group, $account, $request->new_email, $confirmedFromIp, (int) $request->id);

        return $request->new_email;
    }

    /*
    |--------------------------------------------------------------------------
    | Writing the change
    |--------------------------------------------------------------------------
    */

    /**
     * Move the address on the account and close out the audit row.
     *
     * @param  int|null  $requestId  The cp_emailchange row to close, or null
     *                               when the change was applied without a
     *                               confirmation step and needs its own row.
     */
    private function apply(
        ServerGroup $group,
        Account $account,
        string $newEmail,
        string $ip,
        ?int $requestId,
    ): void {
        $connection = $this->connections->connection($group->loginConnection());
        $previous = $account->email;

        $connection
            ->table('login')
            ->where('account_id', $account->account_id)
            ->update(['email' => $newEmail]);

        if ($requestId === null) {
            $connection->table('cp_emailchange')->insert([
                'code' => '',
                'account_id' => $account->account_id,
                'old_email' => $previous,
                'new_email' => $newEmail,
                'request_date' => now(),
                'request_ip' => mb_substr($ip, 0, 39),
                'change_date' => now(),
                'change_ip' => mb_substr($ip, 0, 39),
                'change_done' => 1,
            ]);
        } else {
            $connection
                ->table('cp_emailchange')
                ->where('id', $requestId)
                ->update([
                    'change_done' => 1,
                    'change_date' => now(),
                    'change_ip' => mb_substr($ip, 0, 39),
                    // Cleared so a used token is no longer matchable.
                    'code' => '',
                ]);
        }

        $account->email = $newEmail;
        $account->syncOriginalAttribute('email');
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * @throws ValidationException
     */
    private function validate(ServerGroup $group, Account $account, string $newEmail): void
    {
        $validator = Validator::make(
            ['email' => $newEmail],
            // 39 characters is rAthena's login.email column, not a preference.
            ['email' => ['required', 'string', 'email', 'max:39']],
        );

        $validator->after(function ($validator) use ($group, $account, $newEmail): void {
            if (mb_strtolower($newEmail) === mb_strtolower($account->email)) {
                $validator->errors()->add('email', trans('accounts.email.same_as_current'));

                return;
            }

            if (config('panel.registration.allow_duplicate_emails') !== true
                && $this->emailTaken($group, $newEmail, $account->account_id)) {
                $validator->errors()->add('email', trans('accounts.email.in_use'));
            }
        });

        $validator->validate();
    }

    /**
     * Whether another account already holds this address.
     *
     * Excludes the account asking, so re-confirming the address somebody
     * already has does not report itself as a conflict.
     */
    private function emailTaken(ServerGroup $group, string $email, int $exceptAccountId): bool
    {
        if (config('panel.registration.allow_duplicate_emails') === true) {
            return false;
        }

        return $this->connections
            ->connection($group->loginConnection())
            ->table('login')
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where('account_id', '!=', $exceptAccountId)
            ->exists();
    }

    private function expiryHours(): int
    {
        return max(1, (int) config('panel.email_change.expires_after_hours', 24));
    }
}
