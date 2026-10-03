<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Account\ChangeEmailRequest;
use App\Http\Requests\Account\ConfirmEmailChangeRequest;
use App\Models\Account;
use App\Services\Auth\AccountPasswordChecker;
use App\Services\Mail\AccountMailer;
use App\Services\Rathena\EmailChangeService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Changing the e-mail address on the signed-in account.
 *
 * Ports modules/account/changemail.php and confirmemail.php.
 */
final class EmailController
{
    public function __construct(
        private readonly EmailChangeService $changes,
        private readonly AccountPasswordChecker $passwords,
        private readonly AccountMailer $mailer,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Request a change.
     *
     * @throws ValidationException
     */
    public function update(ChangeEmailRequest $request): JsonResponse
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        $request->ensureIsNotRateLimited();

        $group = $this->servers->current();

        /*
         * The password, not just the session. The address on an account is
         * what password reset trusts, so a stolen session cookie must not be
         * enough to move it. The legacy panel asked for nothing here.
         */
        if (! $this->passwords->matches($group, $account, (string) $request->input('current_password'))) {
            throw ValidationException::withMessages([
                'current_password' => trans('accounts.password.current_incorrect'),
            ]);
        }

        $newEmail = (string) $request->input('email');

        $token = $this->changes->request($group, $account, $newEmail, (string) $request->ip());

        if ($token === null) {
            // Confirmation is off, so the change has already been applied.
            return response()->json([
                'message' => trans('accounts.email.changed'),
                'email' => $account->email,
                'requires_confirmation' => false,
            ]);
        }

        $sent = $this->mailer->sendEmailChangeConfirmation($account, $newEmail, $token);

        return response()->json([
            'message' => trans('accounts.email.confirmation_sent', ['email' => $newEmail]),
            // Still the old address. It does not move until the link is
            // followed, and the client should not show otherwise.
            'email' => $account->email,
            'requires_confirmation' => true,
            'confirmation_sent' => $sent,
        ]);
    }

    /**
     * Follow the confirmation link.
     *
     * @throws ValidationException
     */
    public function confirm(ConfirmEmailChangeRequest $request): JsonResponse
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        $request->ensureIsNotRateLimited();

        $confirmed = $this->changes->confirm(
            $this->servers->current(),
            $account,
            (string) $request->input('token'),
            (string) $request->ip(),
        );

        if ($confirmed === null) {
            return response()->json([
                'message' => trans('accounts.email.invalid'),
            ], 422);
        }

        return response()->json([
            'message' => trans('accounts.email.confirmed'),
            'email' => $confirmed,
        ]);
    }
}
