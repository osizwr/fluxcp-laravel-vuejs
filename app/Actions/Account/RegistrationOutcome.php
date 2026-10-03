<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;

/**
 * What happened when an account was registered.
 *
 * Three facts rather than one, because the caller has to say three different
 * things: that the account is ready to use, that it needs confirming first, or
 * that it needs confirming but the e-mail did not go out and the person should
 * ask for another.
 */
final readonly class RegistrationOutcome
{
    public function __construct(
        public Account $account,
        public bool $requiresConfirmation,
        public bool $confirmationSent,
    ) {}

    /**
     * Whether the account can be used right away.
     */
    public function isUsable(): bool
    {
        return ! $this->requiresConfirmation;
    }
}
