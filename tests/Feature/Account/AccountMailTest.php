<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Mail\AccountConfirmationMail;
use App\Mail\AccountMail;
use App\Mail\EmailChangeConfirmationMail;
use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetMail;
use App\Support\Client\ClientRoutes;
use App\Support\Tokens\SecureToken;
use Illuminate\Mail\Mailables\Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the account e-mails actually render.
 *
 * The flow tests assert that a message was sent and that it carries a link.
 * These assert the contents of both parts, which is where a defect hides: the
 * HTML part can be perfect while the text part is unusable, and only a reader
 * with HTML turned off ever finds out.
 */
final class AccountMailTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://panel.example.test');
        config()->set('game.name', 'Example Online');
    }

    /**
     * @return array<string, array{0: AccountMail, 1: bool}>
     */
    public static function mailables(): array
    {
        $url = 'https://panel.example.test/confirm-account?token='.self::TOKEN.'&server=main';

        return [
            'account confirmation' => [
                new AccountConfirmationMail('merchant', $url, '418302', 48),
                true,
            ],
            'password reset' => [
                new PasswordResetMail('merchant', $url, 2),
                true,
            ],
            'e-mail change' => [
                new EmailChangeConfirmationMail('merchant', 'new@example.test', $url, 24),
                true,
            ],
            'password changed notice' => [
                new PasswordChangedMail('merchant', '198.51.100.10', 'Fri, Oct 3 2026 06:00 UTC'),
                false,
            ],
        ];
    }

    /**
     * Render both parts of a mailable.
     *
     * @return array{html: string, text: string}
     */
    private function render(AccountMail $mail): array
    {
        $content = $mail->content();

        $this->assertInstanceOf(Content::class, $content);
        $this->assertNotNull($content->text, 'Every account e-mail needs a plain-text part.');

        return [
            'html' => $mail->render(),
            'text' => (string) view($content->text, $content->with)->render(),
        ];
    }

    #[Test]
    #[DataProvider('mailables')]
    public function both_parts_render_and_carry_the_brand(AccountMail $mail, bool $hasLink): void
    {
        $parts = $this->render($mail);

        foreach ($parts as $which => $body) {
            $this->assertNotSame('', trim($body), "The {$which} part is empty.");
            $this->assertStringContainsString('Example Online', $body, "The {$which} part lost the brand.");
            $this->assertStringContainsString('merchant', $body, "The {$which} part lost the account name.");
        }

        // The text part must not be HTML that happens to be served as text.
        $this->assertStringNotContainsString('<table', $parts['text']);
        $this->assertStringNotContainsString('<p ', $parts['text']);

        $this->assertSame($hasLink, str_contains($parts['text'], 'panel.example.test/confirm-account'));
    }

    #[Test]
    #[DataProvider('mailables')]
    public function no_part_contains_an_unresolved_blade_expression(AccountMail $mail, bool $hasLink): void
    {
        foreach ($this->render($mail) as $which => $body) {
            $this->assertStringNotContainsString('{{', $body, "The {$which} part has an unrendered expression.");
            $this->assertStringNotContainsString('@if', $body, "The {$which} part has an unrendered directive.");
        }
    }

    #[Test]
    #[DataProvider('mailables')]
    public function the_text_part_never_html_escapes_a_link(AccountMail $mail, bool $hasLink): void
    {
        /*
         * The bug this exists for. Blade's {{ }} escapes, so the `&` between
         * query parameters became `&amp;` -- five literal characters in a
         * plain-text message. Somebody copying the link out of a text-only
         * client pasted a broken URL.
         *
         * It was found by reading a rendered message during an end-to-end run,
         * not by the flow tests, which only looked for the token.
         */
        $text = $this->render($mail)['text'];

        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('&#039;', $text);
        $this->assertStringNotContainsString('&quot;', $text);

        if ($hasLink) {
            $this->assertStringContainsString(
                'token='.self::TOKEN.'&server=main',
                $text,
                'The text part must carry a URL that can be copied and pasted as-is.',
            );
        }
    }

    #[Test]
    #[DataProvider('mailables')]
    public function the_html_part_escapes_the_link_in_its_href(AccountMail $mail, bool $hasLink): void
    {
        if (! $hasLink) {
            $this->assertTrue(true, 'This mailable carries no link.');

            return;
        }

        // The opposite expectation to the text part: inside an href, `&amp;`
        // is the correct encoding.
        $this->assertStringContainsString('&amp;server=main', $this->render($mail)['html']);
    }

    #[Test]
    public function the_subject_carries_the_brand(): void
    {
        $mail = new PasswordResetMail('merchant', 'https://panel.example.test/reset-password', 2);

        $this->assertSame('Example Online — Choose a new password', $mail->envelope()->subject);
    }

    #[Test]
    public function the_subject_omits_the_brand_when_none_is_configured(): void
    {
        config()->set('game.name', '');

        $mail = new PasswordResetMail('merchant', 'https://panel.example.test/reset-password', 2);

        // Not " — Choose a new password" with a dangling dash.
        $this->assertSame('Choose a new password', $mail->envelope()->subject);
    }

    #[Test]
    public function the_reset_mail_contains_no_password_like_value(): void
    {
        $token = SecureToken::generate();

        $mail = new PasswordResetMail(
            'merchant',
            ClientRoutes::url(ClientRoutes::RESET_PASSWORD, [
                'token' => $token->plaintext,
                'server' => 'main',
            ]),
            2,
        );

        foreach ($this->render($mail) as $body) {
            // The token is expected. The digest stored in the database is not:
            // if it appeared, the mail would be disclosing the stored value.
            $this->assertStringContainsString($token->plaintext, $body);
            $this->assertStringNotContainsString($token->digest, $body);
        }
    }
}
