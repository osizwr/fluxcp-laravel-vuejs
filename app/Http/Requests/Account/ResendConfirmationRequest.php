<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * A request for another account confirmation e-mail.
 */
final class ResendConfirmationRequest extends FormRequest
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
            'email.required' => 'Enter the e-mail address you registered with.',
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
     * Two limits, because they stop different things.
     *
     * The per-address one stops a host walking a list of accounts. The
     * per-account one stops repeated requests mailing the same person over and
     * over, which an address limit alone would not -- a distributed set of
     * requests naming one account would pass it.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'resend-confirmation',
            [
                (string) $this->ip() => 5,
                'account:'.(string) $this->input('username') => 3,
            ],
            900,
            'username',
        );
    }
}
