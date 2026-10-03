<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Notice that an account's password has changed.
 *
 * Sent after both a reset and a deliberate change, and it is the only thing
 * that makes an unnoticed account takeover noticeable: somebody who did not
 * make this change learns about it from this mail.
 *
 * It replaces the legacy `newpass` template, which told the account holder
 * their new password instead of telling them something had happened.
 */
final class PasswordChangedMail extends AccountMail
{
    public function __construct(
        private readonly string $username,
        private readonly string $changedFromIp,
        private readonly string $changedAt,
    ) {}

    protected function subjectLine(): string
    {
        return 'Your password was changed';
    }

    protected function viewName(): string
    {
        return 'password-changed';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'username' => $this->username,
            'changedFromIp' => $this->changedFromIp,
            'changedAt' => $this->changedAt,
        ];
    }
}
