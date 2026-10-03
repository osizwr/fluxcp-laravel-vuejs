<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The link that lets somebody choose a new password.
 *
 * Note what this does not contain: a password. The legacy panel generated one
 * and e-mailed it in cleartext, which left a working credential sitting in a
 * mailbox and in every mail server along the way, and meant the account's
 * password was a string the account holder never chose. See
 * docs/MIGRATION_DECISIONS.md (D16).
 */
final class PasswordResetMail extends AccountMail
{
    public function __construct(
        private readonly string $username,
        private readonly string $resetUrl,
        private readonly int $expiresInHours,
    ) {}

    protected function subjectLine(): string
    {
        return 'Choose a new password';
    }

    protected function viewName(): string
    {
        return 'password-reset';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'username' => $this->username,
            'actionUrl' => $this->resetUrl,
            'expiresInHours' => $this->expiresInHours,
        ];
    }
}
