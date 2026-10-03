<?php

declare(strict_types=1);

namespace App\Http\Requests\Account\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-submission throttling for the forms that cause an e-mail to be sent.
 *
 * Without it, registration, "resend my confirmation" and "reset my password"
 * are all endpoints where one HTTP request makes this server send mail to an
 * address an anonymous visitor chose. Unthrottled, that is both a way to
 * flood somebody's inbox using this server's reputation and a quick way to get
 * the sending domain blacklisted.
 *
 * The legacy panel had no limiting on any of them.
 */
trait ThrottlesSubmissions
{
    /**
     * Refuse when a key has been hit too often, and record this attempt.
     *
     * Every limit is checked before any is recorded, so a request that is
     * going to be refused does not also consume the other budgets.
     *
     * @param  array<string, int>  $limits  Key suffix => maximum attempts.
     *
     * @throws ValidationException
     */
    protected function throttleSubmission(
        string $prefix,
        array $limits,
        int $decaySeconds,
        string $reportOn,
    ): void {
        $keys = [];

        foreach ($limits as $suffix => $maxAttempts) {
            $key = $prefix.':'.Str::transliterate(Str::lower($suffix));
            $keys[] = $key;

            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    $reportOn => trans('auth.throttle', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ])->status(429);
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }
}
