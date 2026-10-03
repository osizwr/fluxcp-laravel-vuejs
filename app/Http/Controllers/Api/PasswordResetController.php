<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Account\PasswordResetLinkRequest;
use App\Http\Requests\Account\ResetPasswordRequest;
use App\Services\Auth\SessionRegistry;
use App\Services\Mail\AccountMailer;
use App\Services\Rathena\PasswordResetService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Password reset by e-mail.
 *
 * Ports modules/account/resetpass.php and resetpw.php. See
 * {@see PasswordResetService} for what the legacy flow did that this does not
 * -- the short version is that it e-mailed a password it generated, in
 * cleartext, with a guessable token that never expired.
 */
final class PasswordResetController
{
    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly AccountMailer $mailer,
        private readonly SessionRegistry $sessions,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Ask for a reset link.
     *
     * Always answers the same way. The legacy panel reported
     * `ResetPassFailed` only when the username and e-mail did not match a real
     * account, which made the form a way to test whether an address was
     * registered -- and, because the message also appeared for staff accounts,
     * a way to find out which accounts belonged to game masters.
     *
     * @throws ValidationException
     */
    public function store(PasswordResetLinkRequest $request): JsonResponse
    {
        if (config('panel.password_reset.enabled') !== true) {
            return response()->json([
                'message' => trans('accounts.reset.not_permitted'),
            ], 403);
        }

        $request->ensureIsNotRateLimited();

        $group = $this->servers->get((string) $request->input('server'));
        $this->servers->use($group->key);

        $requested = $this->resets->request(
            $group,
            (string) $request->input('username'),
            (string) $request->input('email'),
            (string) $request->ip(),
        );

        if ($requested !== null) {
            [$account, $token] = $requested;

            $this->mailer->sendPasswordReset($group, $account, $token);
        }

        return response()->json([
            'message' => trans('accounts.reset.requested'),
        ]);
    }

    /**
     * Set a new password using a reset link.
     *
     * @throws ValidationException
     */
    public function update(ResetPasswordRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $group = $this->servers->get((string) $request->input('server'));
        $this->servers->use($group->key);

        $account = $this->resets->reset(
            $group,
            (string) $request->input('token'),
            (string) $request->input('password'),
            (string) $request->ip(),
        );

        if ($account === null) {
            return response()->json([
                'message' => trans('accounts.reset.invalid'),
            ], 422);
        }

        /*
         * Every session, with no exception: whoever is completing a reset is
         * not signed in, and anyone who is signed in as this account at this
         * moment is the problem the reset is solving.
         */
        $this->sessions->revokeOtherSessions($account, null);

        $this->mailer->sendPasswordChangedNotice($account, (string) $request->ip());

        return response()->json([
            'message' => trans('accounts.reset.complete'),
        ]);
    }
}
