<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The link that activates a newly registered account.
 *
 * Until it is followed the account sits in rAthena's state 5 and cannot sign
 * in to the game or the panel, which is what makes confirmation meaningful
 * rather than decorative.
 */
final class AccountConfirmationMail extends AccountMail
{
    public function __construct(
        private readonly string $username,
        private readonly string $confirmationUrl,
        private readonly int $expiresInHours,
    ) {}

    protected function subjectLine(): string
    {
        return 'Confirm your account';
    }

    protected function viewName(): string
    {
        return 'account-confirmation';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'username' => $this->username,
            'actionUrl' => $this->confirmationUrl,
            'expiresInHours' => $this->expiresInHours,
        ];
    }
}
