<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\Rathena\ServerGroup;

/**
 * Compares a submitted password against the value rAthena stores in
 * `login.user_pass`, in whichever of its two formats the server is using.
 *
 * This class exists to keep a weak comparison in exactly one auditable place.
 * It is not how the panel authenticates people day to day: that is a proper
 * hash in `panel_credentials`. This is only used to accept an account's first
 * sign-in after migration, and to keep `login.user_pass` in step when a
 * password changes, because the emulator's login server reads that column
 * directly and the panel cannot change its format.
 *
 * See docs/MIGRATION_DECISIONS.md (D1).
 */
final class RathenaCredentialVerifier
{
    /**
     * Whether the submitted password matches the account's stored value.
     *
     * Both formats are compared with hash_equals. For the MD5 case that is
     * conventional; for the cleartext case it matters more, since a plain
     * string comparison short-circuits on the first differing byte and leaks
     * a prefix oracle. The legacy panel avoided the issue only by accident,
     * by comparing inside SQL.
     */
    public function verify(ServerGroup $group, string $submitted, string $stored): bool
    {
        if ($submitted === '' || $stored === '') {
            return false;
        }

        return hash_equals($stored, $this->encode($group, $submitted));
    }

    /**
     * Encode a password into the format this server group stores.
     *
     * Returns the password unchanged when the server is not using MD5, which
     * is rAthena's default and means the column holds cleartext.
     */
    public function encode(ServerGroup $group, string $password): string
    {
        return $group->loginServer->usesMd5
            ? md5($password)
            : $password;
    }

    /**
     * How this server group stores credentials, for the admin UI to report.
     *
     * Neither format is safe: cleartext is readable outright and unsalted MD5
     * falls to a rainbow table. Surfacing which one is in use lets an operator
     * see the exposure rather than leaving it buried in config, and both are
     * properties of the emulator's schema that the panel cannot fix.
     */
    public function storageFormat(ServerGroup $group): string
    {
        return $group->loginServer->usesMd5 ? 'unsalted-md5' : 'cleartext';
    }

    /**
     * The longest password rAthena's column can hold.
     *
     * `login.user_pass` is varchar(32). With MD5 that is exactly the hash
     * width and any password length is fine; with cleartext storage anything
     * longer than 32 characters would be silently truncated on write and
     * could then never be matched, so registration must reject it.
     */
    public function maximumPasswordLength(ServerGroup $group): ?int
    {
        return $group->loginServer->usesMd5 ? null : 32;
    }
}
