<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Contracts\ChallengesHumanity;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Verification delegated to Google reCAPTCHA.
 *
 * Replaces the legacy branch in Flux_LoginServer::register(), which called
 * file_get_contents() on a URL with the secret and the user's response
 * interpolated into the query string. That had three problems this does not:
 * no timeout, so a slow response hung the request for however long PHP's
 * default socket timeout was; no error handling, so a network failure was a
 * PHP warning and then `json_decode(false)` returning null; and the response
 * token went into a URL, where it lands in proxy and server logs.
 */
final readonly class ReCaptcha implements ChallengesHumanity
{
    private const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(private HttpClient $http) {}

    public function verify(?string $response, string $ipAddress): bool
    {
        if ($response === null || $response === '') {
            return false;
        }

        $secret = (string) config('panel.captcha.recaptcha.secret_key');

        if ($secret === '') {
            throw new RuntimeException(
                'The reCAPTCHA driver is selected but RECAPTCHA_SECRET_KEY is not set.',
            );
        }

        try {
            $result = $this->http
                // A bounded wait. The registration form must not hang because
                // a third party is slow.
                ->timeout(5)
                ->connectTimeout(3)
                ->asForm()
                // Posted, not interpolated into the query string, so neither
                // the secret nor the response token is written to a request log.
                ->post(self::ENDPOINT, [
                    'secret' => $secret,
                    'response' => $response,
                    'remoteip' => $ipAddress,
                ]);
        } catch (Throwable $e) {
            /*
             * Fail closed. An unreachable verifier means we cannot tell a
             * person from a script, and treating "unknown" as "human" would
             * turn an outage at Google into open registration here.
             */
            Log::warning('reCAPTCHA verification could not be completed.', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $result->successful()) {
            Log::warning('reCAPTCHA verification returned an error status.', [
                'status' => $result->status(),
            ]);

            return false;
        }

        return $result->json('success') === true;
    }

    public function isSelfHosted(): bool
    {
        return false;
    }
}
