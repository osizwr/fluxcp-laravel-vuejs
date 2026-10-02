<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Values of rAthena's `login.state` that the panel acts on.
 *
 * rAthena stores a client-facing rejection code in this column and does not
 * document a full enumeration, so only the two values FluxCP recognises are
 * modelled here. Anything else is left as an opaque integer rather than
 * guessed at: use {@see self::tryFrom()} and treat null as "some other state
 * rAthena set", which the panel reports but does not interpret.
 */
enum AccountState: int
{
    case Normal = 0;

    /**
     * Permanently banned. Also the state an account sits in while its
     * registration is awaiting e-mail confirmation, which is why a login
     * attempt has to distinguish the two by looking for an unconfirmed
     * registration record before reporting a ban.
     */
    case PermanentlyBanned = 5;

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::PermanentlyBanned => 'Permanently banned',
        };
    }
}
