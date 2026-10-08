<?php

declare(strict_types=1);

namespace App\Support\Tokens;

use SensitiveParameter;

/**
 * A short numeric code, e-mailed so somebody can type it back.
 *
 * The sibling of {@see SecureToken}, and deliberately a separate class rather
 * than a shorter token: the two are not interchangeable, and the difference is
 * what the rest of the design has to account for.
 *
 *   plaintext   goes in the e-mail. Six digits.
 *   digest      goes in the database, the first 32 characters of its SHA-256,
 *               for the same reason a token is hashed: a database read must
 *               not be a list of working codes.
 *
 * ---------------------------------------------------------------------------
 * Six digits is a million guesses, and that is the whole design
 * ---------------------------------------------------------------------------
 *
 * A 256-bit token is unguessable and needs no help. A six-digit code is not:
 * it is one in a million, which an unattended script works through in minutes.
 * It is only safe because of the things around it, and those are not optional
 * decoration:
 *
 *   - the code is bound to one account, so guesses cannot be spread across
 *     every pending registration at once;
 *   - attempts against it are counted, and the code dies well before a
 *     meaningful fraction of the space has been tried;
 *   - it expires on the same clock as the link.
 *
 * Remove any of those and the code becomes the weakest way into an account.
 * The attempt ceiling lives in AccountConfirmationService, next to the lookup
 * that enforces it.
 *
 * Leading zeros are kept: `random_int` gives 0 to 999999 and the value is
 * padded, so `004182` is as likely as any other code. Dropping to five visible
 * digits for a tenth of them would be a silent reduction in entropy.
 */
final readonly class OneTimeCode
{
    /** How many digits a visitor is asked to type. */
    public const LENGTH = 6;

    private function __construct(
        public string $plaintext,
        public string $digest,
    ) {}

    public static function generate(): self
    {
        $plaintext = str_pad(
            (string) random_int(0, (10 ** self::LENGTH) - 1),
            self::LENGTH,
            '0',
            STR_PAD_LEFT,
        );

        return new self($plaintext, self::digestOf($plaintext));
    }

    /**
     * The lookup digest for a code somebody typed.
     *
     * Deterministic, so the row is found by an indexed equality match rather
     * than by reading every pending registration and comparing.
     */
    public static function digestOf(#[SensitiveParameter] string $plaintext): string
    {
        return substr(hash('sha256', $plaintext), 0, 32);
    }

    /**
     * Whether a submitted value is even the right shape.
     *
     * Checked before the digest is taken so that obvious rubbish -- a pasted
     * sentence, an empty box -- never reaches the database, and so the error
     * for "that is not a code" is reachable without a query.
     */
    public static function looksValid(#[SensitiveParameter] string $candidate): bool
    {
        return preg_match('/^\d{'.self::LENGTH.'}$/', $candidate) === 1;
    }
}
