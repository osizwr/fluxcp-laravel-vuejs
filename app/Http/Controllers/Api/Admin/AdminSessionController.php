<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Auth\AuthenticateAccount;
use App\Enums\AccountLevel;
use App\Enums\LoginFailure;
use App\Exceptions\LoginFailed;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\Auth\LoginAuditLog;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Signing in to the dashboard, at /admin/login.
 *
 * A second door rather than a second lock. It shares the session, the
 * credential store, the rate limiter and the audit log with the player sign-in
 * -- there is one account system and inventing a parallel one would mean two
 * of everything to keep correct -- but it refuses anyone who is not an
 * administrator, and refuses them *before* a session exists.
 *
 * That ordering is the point. Posting the admin form with a valid player's
 * credentials must not leave that player signed in: it would turn a staff-only
 * form into a general-purpose one, and it would tell whoever typed them that
 * the credentials were good.
 *
 * Authorisation is against AccountLevel::Administrator rather than a literal
 * group_id, which is how the rest of the panel asks the same question.
 * config/permissions.php maps rAthena's group 99 to that level out of the box,
 * so "group 99 only" is the default behaviour; an operator who has given a
 * second group administrator rights in that file gets what their file says
 * rather than a hard-coded 99 quietly disagreeing with it.
 */
final class AdminSessionController
{
    public function __construct(
        private readonly AuthenticateAccount $authenticator,
        private readonly LoginAuditLog $audit,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Sign in to the dashboard.
     *
     * Every failure -- wrong password, unknown account, banned, or a perfectly
     * good player account that simply is not staff -- comes back as the same
     * message. The dashboard is not a place to help somebody work out which
     * half of their guess was right, and a distinct "you are not an
     * administrator" reply would confirm a valid username and password to
     * anyone who found one.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $username = (string) $request->input('username');
        $password = (string) $request->input('password');
        $ipAddress = (string) $request->ip();
        $group = (string) $request->input('server');

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

            return $this->refuse();
        }

        if (! $account->accountLevel()->satisfies(AccountLevel::Administrator)) {
            /*
             * Counted against the limiter like any other failure. Without
             * this, the one case where the attacker already holds working
             * credentials would be the one case they could retry without
             * limit.
             */
            $request->recordFailedAttempt();

            /*
             * Recorded as invalid credentials in the account's own audit
             * trail, because that trail is visible to the account holder and
             * the enum has no case for this; the detail that matters to an
             * operator goes to the log instead, where a run of these is worth
             * looking at.
             */
            $this->audit->recordFailure(
                $this->servers->current(),
                $account->account_id,
                $username,
                $ipAddress,
                LoginFailure::InvalidCredentials,
            );

            Log::warning('Refused a dashboard sign-in by a non-administrator.', [
                'account_id' => $account->account_id,
                'group_id' => $account->group_id,
                'ip' => $ipAddress,
            ]);

            return $this->refuse();
        }

        $request->clearRateLimit();

        /*
         * Same session fixation defence as the player sign-in: the id is
         * regenerated so an id planted beforehand cannot be promoted to an
         * administrator's.
         *
         * `remember` is deliberately not honoured here. A long-lived cookie on
         * a staff account is a much worse trade than on a player's, and the
         * dashboard is not somewhere anyone needs to stay signed in to for
         * weeks.
         */
        Auth::login($account);
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
     * The one answer every failed attempt gets.
     *
     * Shaped like Laravel's validation error so the form can render it beside
     * the username field without a special case, and carrying no `reason`:
     * the player sign-in sends one so the client can offer the confirmation
     * step, and there is no equivalent next step to offer here.
     */
    private function refuse(): JsonResponse
    {
        /*
         * Literally the player sign-in's own "those details are not correct",
         * rather than a line of its own. Two messages would be two things to
         * tell apart, and the whole purpose here is that a refused
         * administrator and a refused stranger are indistinguishable. It is
         * also already translated into every locale the panel ships.
         */
        $message = (string) trans(LoginFailure::InvalidCredentials->translationKey());

        return response()->json([
            'message' => $message,
            'errors' => ['username' => [$message]],
        ], 422);
    }
}
