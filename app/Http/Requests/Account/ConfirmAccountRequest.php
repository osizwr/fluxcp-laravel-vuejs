<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Activating a newly registered account, by either route.
 *
 * Open to guests, necessarily: the whole point is that the account cannot sign
 * in until this has been done.
 *
 * Two shapes, because the e-mail offers two ways in:
 *
 *   token               the link. Identifies the registration by itself, so no
 *                       account name is needed -- and a link in an e-mail that
 *                       names the account is one more thing disclosed by a
 *                       forwarded message. (The legacy link carried the name
 *                       and required it.)
 *
 *   username + code     the typed code. The name is required here and is not
 *                       a disclosure: whoever is typing has just registered it.
 *                       It is what scopes six digits to one account, without
 *                       which the code would be guessable against every
 *                       pending registration at once. See OneTimeCode.
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

            /*
             * Exactly one of the two routes. `required_without` on both sides
             * refuses a request carrying neither; a request carrying both is
             * answered by the token, which is the stronger secret.
             */
            'token' => ['required_without:code', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'code' => ['required_without:token', 'nullable', 'string', 'regex:/^\d{6}$/'],
            'username' => ['required_with:code', 'nullable', 'string', 'max:23'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required_without' => 'This confirmation link is incomplete.',
            'token.regex' => 'This confirmation link is not valid.',
            'code.required_without' => 'Enter the code from your e-mail.',
            'code.regex' => 'The code is six digits.',
            'username.required_with' => 'This confirmation is incomplete.',
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
        /*
         * Keyed on the field the request actually used, so a visitor mistyping
         * a code is not throttled out of clicking the link instead. The code's
         * real ceiling is the per-account attempt count in the service; this
         * is the blanket limit on top of it.
         */
        $usedCode = is_string($this->input('code')) && $this->input('code') !== '';

        $this->throttleSubmission(
            $usedCode ? 'confirm-account-code' : 'confirm-account',
            [(string) $this->ip() => 10],
            900,
            $usedCode ? 'code' : 'token',
        );
    }
}
