<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Tokens\SecureToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The properties the recovery flows depend on.
 *
 * These are not incidental details of the implementation. Each one is a thing
 * the legacy `md5(rand() + $account_id)` scheme did not have, and each is load
 * bearing: if the stored value equals the e-mailed value, reading the database
 * is enough to reset anybody's password.
 */
final class SecureTokenTest extends TestCase
{
    #[Test]
    public function the_stored_digest_is_not_the_token(): void
    {
        $token = SecureToken::generate();

        $this->assertNotSame(
            $token->plaintext,
            $token->digest,
            'The digest stored in the database must not be the token that was e-mailed.',
        );

        $this->assertStringNotContainsString(
            $token->digest,
            $token->plaintext,
            'The stored digest must not be a substring of the token.',
        );
    }

    #[Test]
    public function the_token_cannot_be_recovered_from_the_digest(): void
    {
        $token = SecureToken::generate();

        // A digest is one way: the only route from digest back to token is a
        // preimage attack on SHA-256.
        $this->assertSame(
            $token->digest,
            SecureToken::digestOf($token->plaintext),
            'The digest must be reproducible from the token, for the lookup.',
        );

        $this->assertNotSame(
            $token->plaintext,
            SecureToken::digestOf($token->digest),
            'Digesting the digest must not produce the token.',
        );
    }

    #[Test]
    public function the_digest_fits_the_legacy_column(): void
    {
        /*
         * cp_resetpass.code, cp_emailchange.code and cp_createlog.confirm_code
         * are all varchar(32). A digest longer than that would be silently
         * truncated on write and would then never match on read, so every
         * token would appear invalid.
         */
        $this->assertSame(32, strlen(SecureToken::generate()->digest));
    }

    #[Test]
    public function tokens_are_long_enough_to_be_unguessable(): void
    {
        $token = SecureToken::generate();

        // 64 hex characters: 256 bits from random_bytes(32).
        $this->assertSame(64, strlen($token->plaintext));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->plaintext);
    }

    #[Test]
    public function tokens_do_not_repeat(): void
    {
        $tokens = [];

        for ($i = 0; $i < 250; $i++) {
            $tokens[] = SecureToken::generate()->plaintext;
        }

        $this->assertCount(
            250,
            array_unique($tokens),
            'Two generated tokens collided, which a CSPRNG must not produce.',
        );
    }

    #[Test]
    public function matching_accepts_the_token_and_rejects_everything_else(): void
    {
        $token = SecureToken::generate();

        $this->assertTrue(SecureToken::matches($token->plaintext, $token->digest));

        $this->assertFalse(SecureToken::matches($token->digest, $token->digest));
        $this->assertFalse(SecureToken::matches(SecureToken::generate()->plaintext, $token->digest));
        $this->assertFalse(SecureToken::matches('', $token->digest));
        $this->assertFalse(SecureToken::matches($token->plaintext, ''));
    }

    #[Test]
    public function a_truncated_token_does_not_match(): void
    {
        $token = SecureToken::generate();

        $this->assertFalse(
            SecureToken::matches(substr($token->plaintext, 0, 63), $token->digest),
            'A prefix of a valid token must not match it.',
        );
    }

    #[Test]
    public function only_a_well_formed_token_passes_the_shape_check(): void
    {
        $this->assertTrue(SecureToken::looksValid(SecureToken::generate()->plaintext));

        $this->assertFalse(SecureToken::looksValid(''));
        $this->assertFalse(SecureToken::looksValid(str_repeat('a', 63)));
        $this->assertFalse(SecureToken::looksValid(str_repeat('a', 65)));
        // Upper case hex is not what generate() produces, so it is not ours.
        $this->assertFalse(SecureToken::looksValid(str_repeat('A', 64)));
        $this->assertFalse(SecureToken::looksValid(str_repeat('z', 64)));
        // The shapes a hand-written URL might carry.
        $this->assertFalse(SecureToken::looksValid("' OR 1=1 --"));
        $this->assertFalse(SecureToken::looksValid(md5('legacy style code')));
    }
}
