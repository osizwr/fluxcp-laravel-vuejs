<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Account;
use App\Models\PanelCredential;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Resolves authenticated accounts for the session guard.
 *
 * The default Eloquent provider cannot be used unchanged, because rAthena's
 * `login` table has neither a `remember_token` column nor a usable password
 * hash. Both live in the panel's own `panel_credentials` table instead, so
 * the token lookups are redirected there, and credential validation prefers
 * the panel's hash while still accepting rAthena's weaker stored value for
 * accounts that have not yet signed in through this panel.
 *
 * See docs/MIGRATION_DECISIONS.md (D1).
 */
final class AccountUserProvider extends EloquentUserProvider
{
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly RathenaCredentialVerifier $verifier,
        Hasher $hasher,
    ) {
        parent::__construct($hasher, Account::class);
    }

    /**
     * Find an account by its remember-me token.
     *
     * The token is stored alongside the panel credential, scoped to a server
     * group, so a token issued for one group cannot resolve an account in
     * another that happens to share the id.
     */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        $credential = PanelCredential::query()
            ->where('server_group', $this->servers->current()->key)
            ->where('account_id', $identifier)
            ->first();

        if (! $credential instanceof PanelCredential) {
            return null;
        }

        $stored = $credential->remember_token;

        if ($stored === null || $stored === '' || ! hash_equals($stored, (string) $token)) {
            return null;
        }

        return $this->retrieveById($identifier);
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        PanelCredential::query()->updateOrCreate(
            [
                'server_group' => $this->servers->current()->key,
                'account_id' => $user->getAuthIdentifier(),
            ],
            ['remember_token' => $token],
        );

        if ($user instanceof Account) {
            $user->unsetRelation('panelCredential');
        }
    }

    /**
     * Look up an account by name for the guard.
     *
     * Only the account name is honoured. The default implementation would
     * happily build a query from whatever keys the credentials array carries,
     * which on this table could expose `user_pass` to a crafted lookup.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $username = $credentials['userid'] ?? $credentials['username'] ?? null;

        if (! is_string($username) || $username === '') {
            return null;
        }

        return Account::query()
            ->players()
            ->matchingUserid($username)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '' || ! $user instanceof Account) {
            return false;
        }

        $hash = $user->getAuthPassword();

        if ($hash !== '') {
            return $this->hasher->check($password, $hash);
        }

        return $this->verifier->verify(
            $this->servers->current(),
            $password,
            (string) $user->getRawOriginal('user_pass'),
        );
    }

    /**
     * Refresh the panel's stored hash when the hashing options change.
     *
     * Only ever touches `panel_credentials`. rAthena's own column is left
     * exactly as the emulator expects it.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false,
    ): void {
        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '' || ! $user instanceof Account) {
            return;
        }

        $hash = $user->getAuthPassword();

        if ($hash !== '' && ! $force && ! $this->hasher->needsRehash($hash)) {
            return;
        }

        PanelCredential::query()->updateOrCreate(
            $user->panelCredentialKey(),
            ['password_hash' => $password],
        );

        $user->unsetRelation('panelCredential');
    }
}
