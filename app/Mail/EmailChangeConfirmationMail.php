<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The link that confirms a new e-mail address.
 *
 * Sent to the proposed address, not the current one, because the point is to
 * prove that address is reachable by the account holder before it replaces the
 * one the account's recovery mail goes to.
 */
final class EmailChangeConfirmationMail extends AccountMail
{
    public function __construct(
        private readonly string $username,
        private readonly string $newEmail,
        private readonly string $confirmationUrl,
        private readonly int $expiresInHours,
    ) {}

    protected function subjectLine(): string
    {
        return 'Confirm your new e-mail address';
    }

    protected function viewName(): string
    {
        return 'email-change-confirmation';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'username' => $this->username,
            'newEmail' => $this->newEmail,
            'actionUrl' => $this->confirmationUrl,
            'expiresInHours' => $this->expiresInHours,
        ];
    }
}
