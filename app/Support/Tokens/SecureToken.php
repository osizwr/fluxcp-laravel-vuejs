<?php

declare(strict_types=1);

namespace App\Support\Tokens;

/**
 * A single-use token for an account recovery flow.
 *
 * Two values, deliberately different:
 *
 *   plaintext   goes in the e-mail. 64 hex characters, 256 bits of entropy
 *               from random_bytes().
 *
 *   digest      goes in the database. The first 32 characters of the
 *               plaintext's SHA-256, because somebody reading the table must
 *               not be able to use what they find there.
 *
 * ---------------------------------------------------------------------------
 * Why a truncated digest rather than a full one
 * ---------------------------------------------------------------------------
 *
 * The legacy columns -- `cp_resetpass.code`, `cp_emailchange.code`,
 * `cp_createlog.confirm_code` -- are all varchar(32). A full SHA-256 is 64
 * characters and will not fit, and widening them would break an existing
 * FluxCP installation reading the same tables.
 *
 * 128 bits of a SHA-256 is still far beyond what a preimage attack can reach,
 * so truncating costs nothing an attacker can use, while storing the token
 * itself would mean a database read is a password reset. Same shape of
 * constraint as the credential column in D1: the schema is rAthena's and
 * FluxCP's, and the panel works within it rather than around it.
 *
 * What the legacy panel did instead was `md5(rand() + $account_id)`, which is
 * neither unpredictable -- `rand()` is not a cryptographic generator and the
 * account id is known -- nor hashed at rest.
 */
final readonly class SecureToken
{
    private function __construct(
        public string $plaintext,
        public string $digest,
    ) {}

    /**
     * Mint a new token.
     */
    public static function generate(): self
    {
        $plaintext = bin2hex(random_bytes(32));

        return new self($plaintext, self::digestOf($plaintext));
    }

    /**
     * Rebuild the lookup digest for a token somebody presented.
     *
     * Used to find the matching row. Because the digest is deterministic, the
     * lookup is an indexed equality match rather than a scan.
     */
    public static function digestOf(string $plaintext): string
    {
        return substr(hash('sha256', $plaintext), 0, 32);
    }

    /**
     * Whether a presented token matches a stored digest.
     *
     * hash_equals, so the comparison does not leak how much of the digest was
     * correct through its timing.
     */
    public static function matches(string $presented, string $storedDigest): bool
    {
        if ($presented === '' || $storedDigest === '') {
            return false;
        }

        return hash_equals($storedDigest, self::digestOf($presented));
    }

    /**
     * Whether a string could be one of our tokens at all.
     *
     * Cheap rejection before touching the database, and it keeps a malformed
     * value out of a query.
     */
    public static function looksValid(string $presented): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $presented) === 1;
    }
}
