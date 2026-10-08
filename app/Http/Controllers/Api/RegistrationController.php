<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Account\RegisterAccount;
use App\Http\Requests\Account\ConfirmAccountRequest;
use App\Http\Requests\Account\RegisterRequest;
use App\Http\Requests\Account\ResendConfirmationRequest;
use App\Http\Resources\AccountResource;
use App\Services\Mail\AccountMailer;
use App\Services\Notifications\DiscordWebhook;
use App\Services\Rathena\AccountConfirmationService;
use App\Support\Accounts\PendingConfirmationSession;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Registering an account, and confirming one.
 *
 * Ports modules/account/create.php, confirm.php and resend.php.
 */
final class RegistrationController
{
    public function __construct(
        private readonly RegisterAccount $register,
        private readonly AccountConfirmationService $confirmations,
        private readonly AccountMailer $mailer,
        private readonly ServerRegistry $servers,
        private readonly DiscordWebhook $discord,
    ) {}

    /**
     * Register.
     *
     * @throws ValidationException
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        /*
         * Reported as a message rather than a 403. Registration being closed
         * is an operator's decision that the person should be told about, not
         * an authorisation failure.
         */
        if (config('panel.registration.enabled') !== true) {
            return response()->json([
                'message' => trans('accounts.registration.disabled'),
            ], 403);
        }

        $request->ensureIsNotRateLimited();

        $outcome = $this->register->handle(
            serverGroup: (string) $request->input('server'),
            username: (string) $request->input('username'),
            password: (string) $request->input('password'),
            email: (string) $request->input('email'),
            gender: $request->gender(),
            registeredFromIp: (string) $request->ip(),
        );

        /*
         * Announced whether or not the account still needs confirming, which
         * is what the legacy did: it said "Account Created." or "Awaiting
         * confirmation." in the same message.
         */
        $this->discord->notify('registration', sprintf(
            'New registration: %s (%s)',
            $outcome->account->userid,
            $outcome->isUsable() ? 'active' : 'awaiting confirmation',
        ));

        if ($outcome->isUsable()) {
            /*
             * Signed in immediately, as the legacy panel did. The session id
             * is regenerated so that an id planted beforehand cannot be
             * promoted to an authenticated one.
             */
            Auth::login($outcome->account);
            $request->session()->regenerate();

            return AccountResource::make($outcome->account)
                ->additional(['message' => trans('accounts.registration.created')])
                ->response()
                ->setStatusCode(201);
        }

        return response()->json([
            'message' => trans('accounts.registration.confirmation_sent', [
                'email' => $outcome->account->email,
            ]),
            'requires_confirmation' => true,
            /*
             * Reported honestly. If the mail did not go out, the person needs
             * to know to ask for another rather than waiting for one that is
             * not coming.
             */
            'confirmation_sent' => $outcome->confirmationSent,
        ], 201);
    }

    /**
     * Follow a confirmation link.
     *
     * @throws ValidationException
     */
    public function confirm(ConfirmAccountRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $group = $this->servers->get((string) $request->input('server'));
        $this->servers->use($group->key);

        /*
         * The token wins when both are present: it is the stronger secret, and
         * a request carrying both is most likely a client that filled the code
         * in from a link it had already followed.
         */
        $token = (string) $request->input('token');

        $account = $token !== ''
            ? $this->confirmations->confirm($group, $token)
            : $this->confirmations->confirmByCode(
                $group,
                (string) $request->input('username'),
                (string) $request->input('code'),
            );

        if ($account === null) {
            return response()->json([
                'message' => trans('accounts.confirmation.invalid'),
            ], 422);
        }

        // Nothing is pending any more, so the note that allowed a resend
        // without the address has nothing left to authorise.
        PendingConfirmationSession::forget($request);

        return response()->json([
            'message' => trans('accounts.confirmation.confirmed'),
        ]);
    }

    /**
     * Ask for another confirmation e-mail.
     *
     * Answers identically whether or not anything matched. A form that says
     * "no such account" is a form that tells you which accounts exist.
     *
     * @throws ValidationException
     */
    public function resend(ResendConfirmationRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $group = $this->servers->get((string) $request->input('server'));
        $this->servers->use($group->key);

        $username = (string) $request->input('username');

        /*
         * A session that has already proved the account is its own -- by
         * signing in with the right password and being turned away only for
         * the confirmation -- does not have to quote the address back. The
         * resend goes to the one on the account, which is the address the
         * registration used. See {@see PendingConfirmationSession}.
         */
        $reissued = $request->ownershipAlreadyProved()
            ? $this->confirmations->reissueForVerifiedOwner($group, $username)
            : $this->confirmations->reissue($group, $username, (string) $request->input('email'));

        if ($reissued !== null) {
            [$account, $secrets] = $reissued;

            $this->mailer->sendAccountConfirmation($group, $account, $secrets);
        }

        return response()->json([
            'message' => trans('accounts.confirmation.resent'),
        ]);
    }
}
