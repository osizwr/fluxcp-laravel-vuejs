<?php

declare(strict_types=1);

namespace App\Support\Client;

/**
 * The client paths that server-generated links point at.
 *
 * The panel is a single-page application, so a link in an e-mail is a path the
 * Vue router has to recognise, not a Laravel route. Laravel therefore cannot
 * check these for us: `route()` would only confirm that the catch-all SPA
 * route exists, which it does for every path including a misspelt one.
 *
 * They live here, in one place, rather than being written out at each call
 * site, and tests/Feature/Account/ClientLinkTest.php asserts that every path
 * below appears in resources/js/router/index.ts. Without that, renaming a
 * client route silently turns every outstanding confirmation e-mail into a
 * "page not found" -- and the people affected are the ones who cannot sign in
 * to report it.
 */
final readonly class ClientRoutes
{
    public const CONFIRM_ACCOUNT = '/confirm-account';

    public const RESEND_CONFIRMATION = '/resend-confirmation';

    public const FORGOT_PASSWORD = '/forgot-password';

    public const RESET_PASSWORD = '/reset-password';

    public const CONFIRM_EMAIL_CHANGE = '/confirm-email';

    public const SIGN_IN = '/sign-in';

    public const REGISTER = '/register';

    /**
     * Every path above, for the test that checks them against the router.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CONFIRM_ACCOUNT,
            self::RESEND_CONFIRMATION,
            self::FORGOT_PASSWORD,
            self::RESET_PASSWORD,
            self::CONFIRM_EMAIL_CHANGE,
            self::SIGN_IN,
            self::REGISTER,
        ];
    }

    /**
     * An absolute URL for a client path.
     *
     * Absolute because it is going into an e-mail, where a relative path has
     * nothing to resolve against.
     *
     * @param  array<string, string>  $query
     */
    public static function url(string $path, array $query = []): string
    {
        $url = rtrim((string) config('app.url'), '/').$path;

        return $query === []
            ? $url
            : $url.'?'.http_build_query($query);
    }
}
