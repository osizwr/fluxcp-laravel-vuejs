<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * One message in an operator's broadcast to the player base.
 *
 * Built on the same base as the account e-mails, so it carries the server's
 * brand and has a plain-text alternative -- which a message going to every
 * inbox on the server needs more than any other.
 *
 * The body arrives already rendered by ContentRenderer: Markdown with raw HTML
 * stripped and unsafe links refused, so an administrator cannot put a script
 * into every player's inbox. See docs/MIGRATION_DECISIONS.md (D20).
 */
final class BroadcastMessage extends AccountMail
{
    /**
     * `$subject` is taken: Mailable declares it as a plain property, and
     * redeclaring it readonly is a fatal error.
     */
    public function __construct(
        private readonly string $subjectText,
        private readonly string $bodyText,
        private readonly string $bodyHtml,
    ) {}

    protected function subjectLine(): string
    {
        return $this->subjectText;
    }

    protected function viewName(): string
    {
        return 'broadcast';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return ['bodyText' => $this->bodyText, 'bodyHtml' => $this->bodyHtml];
    }
}
