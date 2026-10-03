<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Following the link that activates a newly registered account.
 *
 * Open to guests, necessarily: the whole point is that the account cannot sign
 * in until this has been done.
 *
 * The legacy link carried the account name as well as the code and the lookup
 * required both. The token alone identifies the row, so the name is not here
 * -- a link in an e-mail that names the account is one more thing disclosed by
 * a forwarded message.
 */
final class ConfirmAccountRequest extends FormRequest
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
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'This confirmation link is incomplete.',
            'token.regex' => 'This confirmation link is not valid.',
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
            'confirm-account',
            [(string) $this->ip() => 10],
            900,
            'token',
        );
    }
}
