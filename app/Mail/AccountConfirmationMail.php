<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The code and link that activate a newly registered account.
 *
 * Both, because they suit different moments. Somebody who registered on a
 * phone and has the panel open in another tab types the code; somebody who
 * comes back to the mail two days later clicks the link. Either confirms, and
 * confirming spends both.
 *
 * Until one of them is used the account sits in rAthena's state 5 and cannot
 * sign in to the game or the panel, which is what makes confirmation
 * meaningful rather than decorative.
 */
final class AccountConfirmationMail extends AccountMail
{
    public function __construct(
        private readonly string $username,
        private readonly string $confirmationUrl,
        private readonly string $confirmationCode,
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
            'code' => $this->confirmationCode,
            'expiresInHours' => $this->expiresInHours,
        ];
    }
}
