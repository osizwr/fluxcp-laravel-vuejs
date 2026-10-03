<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * A request for a password reset link.
 *
 * Both the account name and the address on it are required, as in the legacy
 * panel. Requiring both means the form cannot be used to mail an arbitrary
 * address, and cannot be used to find out whether an address is registered.
 */
final class PasswordResetLinkRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:23'],
            'email' => ['required', 'string', 'email', 'max:39'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Enter your account name.',
            'email.required' => 'Enter the e-mail address on the account.',
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
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'password-reset-request',
            [
                (string) $this->ip() => 5,
                'account:'.(string) $this->input('username') => 3,
            ],
            900,
            'username',
        );
    }
}
