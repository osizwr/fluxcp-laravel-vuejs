<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Account;
use App\Support\Rathena\ServerGroup;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Whether a submitted password is an account's current one.
 *
 * There are two places it could be stored, and both have to be accepted:
 *
 *   panel_credentials    the panel's own bcrypt hash, written on every
 *                        successful sign-in and on every password change.
 *                        Tried first, so the normal case never reads rAthena's
 *                        weak column.
 *
 *   login.user_pass      rAthena's own credential, cleartext or unsalted MD5.
 *                        The fallback, for an account that has not signed in
 *                        since the panel was installed, or one created in the
 *                        game or by another tool.
 *
 * This exists as its own class because sign-in and "confirm your current
 * password before changing it" have to agree exactly. When that logic was
 * duplicated the risk was not that one copy would be wrong, but that one copy
 * would be *updated* -- leaving a password that signs in but is rejected as
 * the current password, or the reverse.
 *
 * See docs/MIGRATION_DECISIONS.md (D1) for why the rAthena column cannot
 * simply be migrated to bcrypt.
 */
final readonly class AccountPasswordChecker
{
    public function __construct(
        private Hasher $hasher,
        private RathenaCredentialVerifier $verifier,
    ) {}

    public function matches(ServerGroup $group, Account $account, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        if ($this->matchesPanelCredential($account, $password)) {
            return true;
        }

        return $this->verifier->verify(
            $group,
            $password,
            // getRawOriginal, so no cast or accessor can come between the
            // stored bytes and the comparison.
            (string) $account->getRawOriginal('user_pass'),
        );
    }

    public function matchesPanelCredential(Account $account, string $password): bool
    {
        $hash = $account->panelCredential?->password_hash;

        if ($hash === null || $hash === '') {
            return false;
        }

        return $this->hasher->check($password, $hash);
    }
}
