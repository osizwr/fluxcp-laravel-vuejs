<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AuthenticateAccount;
use App\Exceptions\LoginFailed;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\Auth\LoginAuditLog;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Signing in and out, and reading the signed-in account.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthenticateAccount $authenticator,
        private readonly LoginAuditLog $audit,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Sign in.
     *
     * @throws ValidationException
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $group = (string) $request->input('server');
        $username = (string) $request->input('username');
        $password = (string) $request->input('password');
        $ipAddress = (string) $request->ip();

        try {
            $account = $this->authenticator->handle($group, $username, $password, $ipAddress);
        } catch (LoginFailed $e) {
            $request->recordFailedAttempt();

            $this->audit->recordFailure(
                $this->servers->current(),
                $e->accountId,
                $username,
                $ipAddress,
                $e->reason,
            );

            /*
             * Reported against the username field so the client can show it
             * beside the form. The message for bad credentials is deliberately
             * vague about which half was wrong; the ban and confirmation
             * messages are specific, but they are only reachable once the
             * password has already been verified, so they disclose nothing to
             * someone guessing.
             */
            throw ValidationException::withMessages([
                'username' => trans($e->reason->translationKey()),
            ]);
        }

        $request->clearRateLimit();

        /*
         * Regenerating the session id on sign-in is what prevents session
         * fixation: an attacker who planted a session id cannot have it
         * promoted to an authenticated one.
         */
        Auth::login($account, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->audit->recordSuccess(
            $this->servers->current(),
            $account->account_id,
            $username,
            $ipAddress,
        );

        return AccountResource::make($account->load('credit'))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Sign out.
     *
     * The session is invalidated and its token regenerated, so the cookie left
     * in the browser cannot be replayed and the next form submission gets a
     * fresh CSRF token.
     */
    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * The signed-in account.
     */
    public function show(Request $request): AccountResource
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return AccountResource::make($account->load('credit'));
    }
}
