<?php

declare(strict_types=1);

namespace App\Support\Tokens;

/**
 * The pair a confirmation e-mail carries: a link and a code.
 *
 * Two secrets rather than one, because they are asked to do different things.
 * The link has to survive being clicked from a mail client days later, so it
 * is a 256-bit token. The code has to be read off a screen and typed, so it is
 * six digits and is only safe because its attempts are counted.
 *
 * Deriving one from the other was the tempting alternative and is worse: a
 * link carrying six digits is a six-digit secret in a URL, and URLs are
 * written to history, proxies and referrer headers.
 *
 * Either one confirms the account, and confirming clears both.
 */
final readonly class ConfirmationSecrets
{
    public function __construct(
        public SecureToken $token,
        public OneTimeCode $code,
    ) {}
}
