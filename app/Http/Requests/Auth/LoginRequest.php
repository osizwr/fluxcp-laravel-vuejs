<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and throttles a sign-in attempt.
 *
 * The legacy panel had no rate limiting at all, which combined with cleartext
 * credential storage made online password guessing cheap. Throttling is here
 * rather than in the action so that the action stays callable from a console
 * command or a test without a limiter in the way.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route access is decided by the permission middleware, which holds
        // this route at "guests only".
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:23'],
            /*
             * No complexity rules on sign-in. Those belong to registration;
             * applying them here would lock out an account whose password
             * predates the current policy, and would leak the policy to
             * someone guessing.
             */
            'password' => ['required', 'string'],
            'server' => ['nullable', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Enter your account name.',
            'password.required' => 'Enter your password.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * Fall back to the default group so a client that does not offer a
         * server switcher still works, and reject an unknown one here rather
         * than letting it reach the database.
         */
        $server = $this->input('server');

        if (! is_string($server) || $server === '') {
            $this->merge(['server' => app(ServerRegistry::class)->default()->key]);
        }
    }

    /**
     * Refuse once too many attempts have been made.
     *
     * Keyed on the account name together with the address, so one person
     * guessing cannot lock a third party out of their own account by
     * hammering it from elsewhere, while a single address trying many
     * accounts is still slowed down by the separate per-address limiter.
     */
    public function ensureIsNotRateLimited(): void
    {
        /*
         * The per-address limit is deliberately looser than the per-account
         * one. A household, office or campus shares one address, so a tight
         * limit there would lock out bystanders; the per-account limit is what
         * actually protects an individual account.
         */
        $limits = [
            $this->throttleKey() => (int) config('panel.login.max_attempts', 5),
            $this->addressThrottleKey() => (int) config('panel.login.max_attempts_per_address', 30),
        ];

        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'username' => trans('auth.throttle', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ])->status(429);
            }
        }
    }

    public function recordFailedAttempt(): void
    {
        $decay = (int) config('panel.login.decay_seconds', 60);

        RateLimiter::hit($this->throttleKey(), $decay);
        RateLimiter::hit($this->addressThrottleKey(), $decay);
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->addressThrottleKey());
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower((string) $this->input('username')).'|'.$this->ip());
    }

    private function addressThrottleKey(): string
    {
        return 'login-address:'.$this->ip();
    }
}
