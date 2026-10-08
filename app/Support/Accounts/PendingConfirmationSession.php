<?php

declare(strict_types=1);

namespace App\Support\Accounts;

use App\Enums\LoginFailure;
use App\Services\Rathena\AccountConfirmationService;
use Illuminate\Http\Request;

/**
 * A note that this browser has proved it owns an account still awaiting
 * confirmation.
 *
 * Resending a confirmation mail cannot be done on an account name alone:
 * knowing a name would otherwise be enough to make the panel mail its owner,
 * and to confirm the name exists. The address on the account is the second
 * factor that closes that, which is why
 * {@see AccountConfirmationService::reissue()} matches on
 * both.
 *
 * Somebody who has just been turned away from sign-in by
 * {@see LoginFailure::PendingConfirmation} has already supplied something
 * strictly stronger: the account's password. The reason is only reachable once
 * the password has been checked -- see
 * {@see LoginFailure::followsSuccessfulCredentialCheck()} -- so asking them for
 * the address as well proves nothing further. It only asks somebody to recall,
 * on the spot, which of their addresses they signed up with.
 *
 * So the attempt leaves this note, and a resend naming the same account on the
 * same session is taken as coming from the owner and sent to the address the
 * account already carries. The note says nothing the visitor did not just
 * demonstrate, and it is bound to one session: it cannot be replayed from
 * anywhere else, and it dies with the session.
 */
final class PendingConfirmationSession
{
    private const KEY = 'account.pending_confirmation';

    /**
     * Record that the password for this account has just been verified.
     */
    public static function remember(Request $request, string $server, string $username): void
    {
        $request->session()->put(self::KEY, [
            'server' => $server,
            'username' => mb_strtolower($username),
        ]);
    }

    /**
     * Whether this session may resend for the named account without quoting
     * its address back.
     *
     * The server is part of the comparison because an account name is only
     * unique within one. Taking a note left on one server as proof of
     * ownership on another would mail whoever happens to hold the same name
     * elsewhere.
     */
    public static function vouchesFor(Request $request, string $server, string $username): bool
    {
        $noted = $request->session()->get(self::KEY);

        if (! is_array($noted)) {
            return false;
        }

        return ($noted['server'] ?? null) === $server
            && ($noted['username'] ?? null) === mb_strtolower($username);
    }

    /**
     * Drop the note once the account is confirmed, so it does not outlive the
     * one thing it was for.
     */
    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
