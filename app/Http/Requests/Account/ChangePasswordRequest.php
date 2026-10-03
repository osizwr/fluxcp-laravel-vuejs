<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Changing the password of the signed-in account.
 */
final class ChangePasswordRequest extends FormRequest
{
    use ThrottlesSubmissions;

    public function authorize(): bool
    {
        // The permission middleware holds this route at Player, so there is a
        // session by the time this runs.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * No policy rules on the current password, for the same reason
             * sign-in has none: it may predate the current policy, and
             * rejecting it here would lock the account out of changing it.
             */
            'current_password' => ['required', 'string'],

            'password' => ['required', 'string', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.different' => 'Choose a password that is different from your current one.',
        ];
    }

    /**
     * Throttled on the account, because this endpoint will tell you whether a
     * password is correct and a session is not expensive to obtain.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'change-password',
            ['account:'.(string) $this->user()?->getAuthIdentifier() => 10],
            900,
            'current_password',
        );
    }
}
