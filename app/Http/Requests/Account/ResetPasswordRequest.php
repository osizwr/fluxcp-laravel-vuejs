<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Completing a password reset with a chosen password.
 *
 * Note what is not here: no account name, and no account id. The token is the
 * only secret, so it is the only thing the link carries. The legacy flow put
 * the account id in the URL as well and keyed the lookup on both, which added
 * nothing -- the code already identified the row -- while making the link
 * disclose which account it was for.
 */
final class ResetPasswordRequest extends FormRequest
{
    use ThrottlesSubmissions;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server' => ['nullable', 'string', 'max:64'],

            /*
             * Exactly the shape SecureToken mints: 64 hex characters. Anything
             * else cannot match a stored digest, so it is rejected before it
             * reaches a query.
             */
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],

            // Policy belongs to the service; agreement between the two boxes
            // belongs to the form.
            'password' => ['required', 'string', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'This reset link is incomplete. Request a new one.',
            'token.regex' => 'This reset link is not valid. Request a new one.',
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $server = $this->input('server');

        if (! is_string($server) || $server === '') {
            $this->merge(['server' => app(ServerRegistry::class)->default()->key]);
        }
    }

    /**
     * Keyed on the address, because a token is the only other thing this
     * endpoint sees and keying on it would give an attacker a fresh budget per
     * guess.
     *
     * The limit exists to make guessing pointless rather than merely
     * expensive: at 256 bits there is nothing to find, but an unthrottled
     * endpoint still invites somebody to try.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'password-reset',
            [(string) $this->ip() => 10],
            900,
            'token',
        );
    }
}
