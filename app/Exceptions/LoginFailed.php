<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\LoginFailure;
use RuntimeException;

/**
 * A sign-in attempt was refused.
 *
 * Carries the reason as an enum rather than a message so the caller decides
 * how much to disclose: the HTTP layer shows a deliberately vague message for
 * a bad password, while the audit log records exactly which check failed.
 */
final class LoginFailed extends RuntimeException
{
    public function __construct(
        public readonly LoginFailure $reason,
        public readonly ?int $accountId = null,
    ) {
        parent::__construct("Sign-in refused: {$reason->name}", $reason->value);
    }

    public static function because(LoginFailure $reason, ?int $accountId = null): self
    {
        return new self($reason, $accountId);
    }
}
