<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Following the link that confirms a new e-mail address.
 *
 * Requires a session, and the request has to belong to the signed-in account.
 * That is the legacy behaviour from confirmemail.php and worth keeping: it
 * means a token read out of a mailbox is not on its own enough to move
 * somebody's address.
 */
final class ConfirmEmailChangeRequest extends FormRequest
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

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'confirm-email-change',
            ['account:'.(string) $this->user()?->getAuthIdentifier() => 10],
            900,
            'token',
        );
    }
}
