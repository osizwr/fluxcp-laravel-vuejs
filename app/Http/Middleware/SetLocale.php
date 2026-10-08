<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the request's language.
 *
 * The panel's language is the visitor's choice, not the operator's, so it is
 * read from a cookie the client writes rather than from configuration. The
 * configured `app.locale` remains the default for anybody who has not chosen.
 *
 * It is a plain cookie rather than an encrypted one on purpose: the client
 * writes it in JavaScript when somebody picks a language, and Laravel's
 * encrypted cookies cannot be written from there. Nothing about it is secret,
 * and the value is checked against the supported list below before it is used
 * -- so the worst a tampered cookie achieves is the language it asked for.
 *
 * `Accept-Language` is deliberately not consulted. The visitor's explicit
 * choice is the only signal here, because a Filipino player on an
 * English-configured browser would otherwise have their choice overridden by
 * their operating system every time they arrived.
 */
final class SetLocale
{
    /**
     * The languages this panel ships, mirroring resources/js/i18n.
     *
     * Kept as a constant rather than config: adding one means adding a locale
     * file on both sides, so a value an operator could change without doing
     * that would only produce a half-translated panel.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['en', 'tl', 'pt_BR', 'th', 'ms', 'id'];

    public const COOKIE = 'panel_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $this->fromCookie($request);

        if ($chosen !== null) {
            app()->setLocale($chosen);
        }

        return $next($request);
    }

    /**
     * The requested locale, or null when there is no usable one.
     *
     * The client writes BCP 47 (`pt-BR`) because that is what belongs in a
     * `lang` attribute; Laravel names directories with an underscore
     * (`pt_BR`). The one translation happens here rather than in either of the
     * two places that would otherwise each have to know about the other.
     */
    private function fromCookie(Request $request): ?string
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $normalised = str_replace('-', '_', $raw);

        return in_array($normalised, self::SUPPORTED, strict: true) ? $normalised : null;
    }
}
