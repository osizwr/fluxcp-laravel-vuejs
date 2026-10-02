<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a sign-in attempt was refused.
 *
 * The integer values are FluxCP's Flux_LoginError constants, preserved so that
 * historical `cp_loginlog.error_code` rows written by the legacy panel keep
 * their meaning when read through this one.
 */
enum LoginFailure: int
{
    case Unexpected = 0;
    case UnknownServer = 1;
    case InvalidCredentials = 2;
    case TemporarilyBanned = 3;
    case PermanentlyBanned = 4;
    case IpBanned = 5;
    case InvalidSecurityCode = 6;
    case PendingConfirmation = 7;

    /**
     * The message shown to the person attempting to sign in.
     *
     * Note that InvalidCredentials is deliberately vague: it must not reveal
     * whether the account exists. The ban and confirmation cases are specific
     * because they are only reached once the password has already been
     * verified, so they disclose nothing to someone guessing.
     */
    public function translationKey(): string
    {
        return 'auth.failure.'.match ($this) {
            self::Unexpected => 'unexpected',
            self::UnknownServer => 'unknown_server',
            self::InvalidCredentials => 'invalid_credentials',
            self::TemporarilyBanned => 'temporarily_banned',
            self::PermanentlyBanned => 'permanently_banned',
            self::IpBanned => 'ip_banned',
            self::InvalidSecurityCode => 'invalid_security_code',
            self::PendingConfirmation => 'pending_confirmation',
        };
    }

    /**
     * Whether this outcome was reached before the password was checked.
     *
     * Attempts refused this early are not evidence about the credentials, so
     * they are recorded but must not count towards per-account lockout.
     */
    public function precedesCredentialCheck(): bool
    {
        return match ($this) {
            self::UnknownServer, self::IpBanned, self::InvalidSecurityCode => true,
            default => false,
        };
    }

    /**
     * Whether the correct password was supplied but the account may not sign
     * in. Used to decide whether an attempt is worth surfacing to the account
     * owner in their own login history.
     */
    public function followsSuccessfulCredentialCheck(): bool
    {
        return match ($this) {
            self::TemporarilyBanned, self::PermanentlyBanned, self::PendingConfirmation => true,
            default => false,
        };
    }
}
