<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Account\ChangePasswordRequest;
use App\Models\Account;
use App\Services\Auth\AccountPasswordChecker;
use App\Services\Auth\SessionRegistry;
use App\Services\Mail\AccountMailer;
use App\Services\Rathena\RathenaAccountService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Changing the signed-in account's password.
 *
 * Ports modules/account/changepass.php.
 */
final class PasswordController
{
    public function __construct(
        private readonly AccountPasswordChecker $passwords,
        private readonly RathenaAccountService $accounts,
        private readonly SessionRegistry $sessions,
        private readonly AccountMailer $mailer,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        $request->ensureIsNotRateLimited();

        $group = $this->servers->current();

        if (! $this->passwords->matches($group, $account, (string) $request->input('current_password'))) {
            throw ValidationException::withMessages([
                'current_password' => trans('accounts.password.current_incorrect'),
            ]);
        }

        // Throws ValidationException against the 'password' field if the new
        // password fails the policy, which is what the client shows.
        $this->accounts->changePassword(
            $account,
            (string) $request->input('password'),
            (string) $request->ip(),
        );

        /*
         * The account holder keeps this session and loses every other one.
         *
         * The legacy panel did the reverse: it logged the account holder out
         * and left other sessions untouched, so somebody who had already
         * signed in with the old password kept their access. Changing a
         * password is most often a response to exactly that, which made the
         * legacy behaviour the wrong way round. See
         * docs/MIGRATION_DECISIONS.md (D18).
         */
        $request->session()->regenerate();

        $othersRevoked = $this->sessions->revokeOtherSessions(
            $account,
            $request->session()->getId(),
        );

        $this->mailer->sendPasswordChangedNotice($account, (string) $request->ip());

        return response()->json([
            'message' => trans('accounts.password.changed'),
            // Reported rather than assumed: with a session driver that cannot
            // be queried, other sessions survive and saying otherwise would be
            // a false reassurance.
            'other_sessions_revoked' => $othersRevoked,
        ]);
    }
}
