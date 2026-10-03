<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Shared shape for the account credential e-mails.
 *
 * Every one of these carries the server's brand rather than the application's,
 * because the recipient knows the name of the game server they registered on
 * and not the name of the panel software running it.
 *
 * All four are plain HTML with a text alternative. A mail client that refuses
 * to render HTML is common enough that a confirmation link reachable only from
 * the HTML part is a link some people cannot use.
 */
abstract class AccountMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The subject line, without the brand prefix the envelope adds.
     */
    abstract protected function subjectLine(): string;

    /**
     * The blade view name under resources/views/mail, without extension.
     *
     * Named viewName rather than view because Mailable::view() already exists
     * as a concrete builder method, and overriding it as abstract is a fatal
     * error rather than a redeclaration.
     */
    abstract protected function viewName(): string;

    /**
     * Data the view needs, beyond the branding added here.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    public function envelope(): Envelope
    {
        $brand = (string) config('game.name', '');

        return new Envelope(
            subject: $brand === ''
                ? $this->subjectLine()
                : $brand.' — '.$this->subjectLine(),
        );
    }

    public function content(): Content
    {
        $branding = [
            'gameName' => (string) config('game.name', ''),
            'gameShortName' => (string) config('game.short_name', ''),
            'gameUrl' => (string) (config('game.links.website') ?: config('app.url')),
            'subjectLine' => $this->subjectLine(),
        ];

        return new Content(
            view: 'mail.'.$this->viewName(),
            text: 'mail.'.$this->viewName().'-text',
            with: [...$branding, ...$this->payload()],
        );
    }
}
