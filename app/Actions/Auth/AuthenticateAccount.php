<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginFailure;
use App\Exceptions\LoginFailed;
use App\Models\Account;
use App\Models\PanelCredential;
use App\Services\Auth\AccountPasswordChecker;
use App\Services\Auth\IpBanService;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Verifies an account's credentials and that it is allowed to sign in.
 *
 * The order of checks reproduces Flux_SessionData::login(), because the order
 * is observable: an IP ban is reported before a bad password, so a banned
 * address learns nothing about which accounts exist.
 *
 *   1. the server group exists
 *   2. the address is not IP-banned
 *   3. the credentials match
 *   4. any lapsed temporary ban is cleared, and a live one refuses
 *   5. an unconfirmed registration is distinguished from a permanent ban
 *   6. a permanent ban refuses
 *
 * CAPTCHA is deliberately not here. It validates a form, not an account, so it
 * belongs to the request rules; keeping it out means this action can be called
 * from a console command or a test without a challenge.
 *
 * On success the submitted password is also written to the panel's own hashed
 * credential store, which is what upgrades accounts away from rAthena's
 * cleartext column over time. See docs/MIGRATION_DECISIONS.md (D1).
 */
final readonly class AuthenticateAccount
{
    /**
     * A real bcrypt hash of a random string, verified against when no account
     * matches so that the failure costs the same as a wrong password.
     *
     * It must be a genuine hash: a malformed one makes the hasher bail out
     * early and reintroduces exactly the timing difference it is here to
     * remove. Nothing is expected to ever match it.
     */
    private const TIMING_EQUALISATION_HASH =
        '$2y$12$a5on5OAs6GGouzpS7IpmtO1uWg2L7A1543xEUM9wCA20ZHJC5Ty.m';

    public function __construct(
        private ServerRegistry $servers,
        private AccountPasswordChecker $passwords,
        private IpBanService $ipBans,
        private Hasher $hasher,
        private ConnectionResolverInterface $connections,
    ) {}

    /**
     * @throws LoginFailed
     */
    public function handle(
        ?string $groupKey,
        string $username,
        string $password,
        string $ipAddress,
    ): Account {
        $group = $this->resolveGroup($groupKey);

        // The registry must be pointed at the group before any model query,
        // since models resolve their connection from it.
        $this->servers->use($group->key);

        $this->assertAddressNotBanned($group, $ipAddress);

        $account = $this->verifyCredentials($group, $username, $password);

        $this->assertNotTemporarilyBanned($group, $account);
        $this->assertRegistrationConfirmed($group, $account);
        $this->assertNotPermanentlyBanned($account);

        $this->storePanelCredential($group, $account, $password);

        return $account;
    }

    private function resolveGroup(?string $groupKey): ServerGroup
    {
        if ($groupKey === null || $groupKey === '') {
            return $this->servers->default();
        }

        if (! $this->servers->has($groupKey)) {
            throw LoginFailed::because(LoginFailure::UnknownServer);
        }

        return $this->servers->get($groupKey);
    }

    private function assertAddressNotBanned(ServerGroup $group, string $ipAddress): void
    {
        if (config('panel.login.allow_ip_banned') === true) {
            return;
        }

        if ($this->ipBans->isBanned($group, $ipAddress)) {
            throw LoginFailed::because(LoginFailure::IpBanned);
        }
    }

    /**
     * Find the account and check the password.
     *
     * Which credential store counts is decided by
     * {@see AccountPasswordChecker}, shared with the change-password endpoint
     * so that the two cannot disagree about what an account's current password
     * is.
     *
     * When no account matches, a hash is still verified against a dummy value
     * before failing. Without that, "no such account" returns measurably
     * faster than "wrong password" and the endpoint becomes an account
     * enumeration oracle.
     */
    private function verifyCredentials(ServerGroup $group, string $username, string $password): Account
    {
        $account = Account::query()
            ->players()
            ->matchingUserid($username)
            ->first();

        if (! $account instanceof Account) {
            $this->hasher->check($password, self::TIMING_EQUALISATION_HASH);

            throw LoginFailed::because(LoginFailure::InvalidCredentials);
        }

        if (! $this->passwords->matches($group, $account, $password)) {
            throw LoginFailed::because(LoginFailure::InvalidCredentials, $account->account_id);
        }

        return $account;
    }

    /**
     * Refuse a live temporary ban, and clear a lapsed one.
     *
     * rAthena does not tidy up after itself here: `unban_time` keeps its old
     * timestamp after the ban expires, and the legacy panel zeroed it on the
     * account's next sign-in attempt. That behaviour is preserved, because
     * the game server reads the same column and would otherwise keep
     * reporting the account as banned.
     */
    private function assertNotTemporarilyBanned(ServerGroup $group, Account $account): void
    {
        if ($account->unban_time <= 0) {
            return;
        }

        if (! $account->isTemporarilyBanned()) {
            $this->connections
                ->connection($group->loginConnection())
                ->table('login')
                ->where('account_id', $account->account_id)
                ->update(['unban_time' => 0]);

            $account->unban_time = 0;
            $account->syncOriginalAttribute('unban_time');

            return;
        }

        if (config('panel.login.allow_temporarily_banned') === true) {
            return;
        }

        throw LoginFailed::because(LoginFailure::TemporarilyBanned, $account->account_id);
    }

    /**
     * Tell an unconfirmed registration apart from a permanent ban.
     *
     * Both sit in state 5. The only thing distinguishing them is an
     * unconfirmed row in the registration audit table, so this check must run
     * before the permanent-ban check or every new registrant is told they are
     * banned.
     */
    private function assertRegistrationConfirmed(ServerGroup $group, Account $account): void
    {
        if (! $account->isPermanentlyBanned()) {
            return;
        }

        $awaitingConfirmation = $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->where('confirmed', 0)
            ->exists();

        if ($awaitingConfirmation) {
            throw LoginFailed::because(LoginFailure::PendingConfirmation, $account->account_id);
        }
    }

    private function assertNotPermanentlyBanned(Account $account): void
    {
        if (! $account->isPermanentlyBanned()) {
            return;
        }

        if (config('panel.login.allow_permanently_banned') === true) {
            return;
        }

        throw LoginFailed::because(LoginFailure::PermanentlyBanned, $account->account_id);
    }

    /**
     * Record the panel's own hash of the password.
     *
     * Runs on every successful sign-in, not just the first, so that the hash
     * follows a password changed in game or by another tool, and so the work
     * factor is refreshed when the application's hashing options change.
     */
    private function storePanelCredential(ServerGroup $group, Account $account, string $password): void
    {
        PanelCredential::query()->updateOrCreate(
            [
                'server_group' => $group->key,
                'account_id' => $account->account_id,
            ],
            [
                'password_hash' => $password,
            ],
        );

        $account->unsetRelation('panelCredential');
    }
}
