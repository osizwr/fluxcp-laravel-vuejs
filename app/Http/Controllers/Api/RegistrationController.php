<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Account\RegisterAccount;
use App\Http\Requests\Account\ConfirmAccountRequest;
use App\Http\Requests\Account\RegisterRequest;
use App\Http\Requests\Account\ResendConfirmationRequest;
use App\Http\Resources\AccountResource;
use App\Services\Mail\AccountMailer;
use App\Services\Rathena\AccountConfirmationService;
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
            birthdate: (string) $request->input('birthdate'),
            registeredFromIp: (string) $request->ip(),
        );

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

        $account = $this->confirmations->confirm($group, (string) $request->input('token'));

        if ($account === null) {
            return response()->json([
                'message' => trans('accounts.confirmation.invalid'),
            ], 422);
        }

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

        $reissued = $this->confirmations->reissue(
            $group,
            (string) $request->input('username'),
            (string) $request->input('email'),
        );

        if ($reissued !== null) {
            [$account, $token] = $reissued;

            $this->mailer->sendAccountConfirmation($group, $account, $token);
        }

        return response()->json([
            'message' => trans('accounts.confirmation.resent'),
        ]);
    }
}
