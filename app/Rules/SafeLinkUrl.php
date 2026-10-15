<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An address that is safe to render as a link somebody will click.
 *
 * Laravel's `url` rule is not enough on its own: `javascript:alert(1)` is a
 * well-formed URL by that rule's reckoning, and this value ends up in an
 * `href` on a page every visitor sees. An administrator is trusted, but a
 * dashboard that turns one compromised staff account into stored XSS across
 * the whole site is a much larger blast radius than one that does not. This is
 * the same concern the news editor's Markdown rendering answers -- see
 * docs/MIGRATION_DECISIONS.md (D20) -- arriving by a different route.
 *
 * Two shapes pass:
 *
 *   https://example.com/client.zip   an absolute http or https address
 *   /files/client.zip                a path on this server's own host
 *
 * The second is there because a server that hosts its own client writes
 * exactly that, and the download page's mirror detection already handles a
 * relative path. A protocol-relative `//evil.test` is refused: it reads like a
 * path and behaves like an absolute address, which is precisely the confusion
 * worth keeping out of a field an operator fills in quickly.
 */
final class SafeLinkUrl implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a web address.');

            return;
        }

        $url = trim($value);

        if (str_starts_with($url, '//')) {
            $fail('The :attribute must start with http://, https:// or a single /.');

            return;
        }

        if (str_starts_with($url, '/')) {
            return;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], strict: true)) {
            $fail('The :attribute must start with http://, https:// or a single /.');

            return;
        }

        if (parse_url($url, PHP_URL_HOST) === null) {
            $fail('The :attribute must include a domain name.');
        }
    }
}
