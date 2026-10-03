<?php

declare(strict_types=1);

namespace App\Services\Donations;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirms that a payment notification really came from the provider.
 *
 * A notification arrives as an ordinary HTTP POST to a public URL. Anybody can
 * send one. The only thing that makes it trustworthy is posting the exact body
 * back to the provider and being told `VERIFIED`, so that is done before a
 * single field of it is believed.
 *
 * ---------------------------------------------------------------------------
 * A note on PayPal IPN
 * ---------------------------------------------------------------------------
 *
 * This implements IPN because that is what FluxCP used and what existing
 * rAthena servers have configured. PayPal has since moved to webhooks, and
 * IPN is maintained but no longer the recommended integration. An operator
 * setting this up today should check whether their account still supports it.
 * That is stated here rather than left for somebody to discover.
 *
 * ---------------------------------------------------------------------------
 * What the legacy did
 * ---------------------------------------------------------------------------
 *
 * Flux_PaymentNotifyRequest opened a raw socket to `www.paypal.com` on port
 * 80 and wrote the HTTP request by hand. Over plain HTTP, the verification
 * step -- the only thing standing between a forged POST and free credits --
 * travelled unencrypted and unauthenticated, so anyone able to see or sit on
 * the connection could answer `VERIFIED` themselves. The endpoint here is
 * HTTPS and goes through the HTTP client, which verifies the certificate.
 */
final readonly class PaymentNotification
{
    public function __construct(private HttpClient $http) {}

    /**
     * Ask the provider whether it sent this.
     *
     * @param  array<string, mixed>  $payload  The POST body exactly as received.
     */
    public function isVerified(array $payload): bool
    {
        $endpoint = (string) config('panel.donations.verify_url');

        if ($endpoint === '') {
            // Refusing is the safe direction: without verification a
            // notification is just an HTTP request from a stranger.
            Log::error('A payment notification arrived but no verification endpoint is configured.');

            return false;
        }

        try {
            $response = $this->http
                ->timeout(20)
                ->connectTimeout(10)
                ->asForm()
                /*
                 * The provider requires its own fields echoed back verbatim,
                 * in order, with `cmd=_notify-validate` first. Re-encoding or
                 * reordering them makes the check fail, which would look like
                 * a forged payment.
                 */
                ->post($endpoint, ['cmd' => '_notify-validate', ...$payload]);
        } catch (Throwable $e) {
            Log::error('A payment notification could not be verified.', [
                'reason' => $e->getMessage(),
            ]);

            // Fail closed. An unreachable verifier means we cannot tell a real
            // payment from a forged one, and treating "unknown" as "genuine"
            // turns an outage into free credits.
            return false;
        }

        if (! $response->successful()) {
            Log::error('The payment verifier returned an error status.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return trim($response->body()) === 'VERIFIED';
    }

    /**
     * Whether the money arrived somewhere this server owns.
     *
     * Without this, a forged -- or genuine but unrelated -- notification for a
     * payment made to somebody else's account would credit the player here.
     */
    public function isOurReceiver(?string $receiver): bool
    {
        $receiver = mb_strtolower(trim((string) $receiver));

        if ($receiver === '') {
            return false;
        }

        foreach ((array) config('panel.donations.receiver_emails', []) as $allowed) {
            if (mb_strtolower(trim((string) $allowed)) === $receiver) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the currency is the one credits are priced in.
     *
     * A payment in another currency has an amount this server cannot convert,
     * so crediting it at the configured rate would be guessing.
     */
    public function isExpectedCurrency(?string $currency): bool
    {
        return mb_strtoupper(trim((string) $currency))
            === mb_strtoupper((string) config('panel.donations.currency', 'USD'));
    }

    /**
     * Whether the payment actually completed.
     *
     * `Pending` is a payment that may still fail, and `Refunded` or
     * `Reversed` is money that has gone back. Only `Completed` is money this
     * server has.
     */
    public function isCompleted(?string $status): bool
    {
        return mb_strtolower(trim((string) $status)) === 'completed';
    }
}
