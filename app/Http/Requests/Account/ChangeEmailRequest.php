<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Changing the e-mail address on the signed-in account.
 *
 * The current password is required, which the legacy panel did not ask for.
 * The e-mail address is what password reset trusts, so letting a hijacked
 * session move it is letting that session take the account permanently --
 * asking for the password means a stolen session cookie alone is not enough.
 */
final class ChangeEmailRequest extends FormRequest
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
            'current_password' => ['required', 'string'],

            // 39 characters is rAthena's login.email column, not a preference.
            'email' => ['required', 'string', 'email', 'max:39', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'email.required' => 'Enter the new e-mail address.',
            'email.confirmed' => 'The two e-mail addresses do not match.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'change-email',
            ['account:'.(string) $this->user()?->getAuthIdentifier() => 5],
            900,
            'current_password',
        );
    }
}
