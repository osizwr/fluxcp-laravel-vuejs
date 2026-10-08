<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Enums\Gender;
use App\Http\Requests\Account\Concerns\ThrottlesSubmissions;
use App\Http\Requests\Account\Concerns\VerifiesCaptcha;
use App\Services\Captcha\CaptchaGate;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A registration submission.
 *
 * Form-shaped rules only. The account name, password and e-mail policy lives
 * in RathenaAccountService, because it also applies to an account created from
 * the console and to a password changed later -- duplicating it here would
 * give two places to change it and one of them would be missed.
 *
 * What is here is what only a form knows: that the two password boxes have to
 * agree, that the two e-mail boxes have to agree, which genders are
 * selectable, and the age gate.
 */
final class RegisterRequest extends FormRequest
{
    use ThrottlesSubmissions;
    use VerifiesCaptcha;

    public function authorize(): bool
    {
        // The route is held at "guests only" by the permission middleware.
        // Whether registration is open at all is a separate question, answered
        // in the controller so it can say so rather than 403.
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
             * Length and character rules are deliberately absent: the service
             * owns them, and it reports against these same field names.
             */
            'username' => ['required', 'string'],

            /*
             * `confirmed` expects password_confirmation, which is what the
             * client sends. The policy itself is the service's.
             */
            'password' => ['required', 'string', 'confirmed'],

            'email' => ['required', 'string', 'email', 'max:39'],

            'gender' => [
                'required',
                'string',
                Rule::in(array_map(fn (Gender $gender): string => $gender->value, Gender::selectable())),
            ],

            ...$this->captchaRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Choose an account name.',
            'password.required' => 'Choose a password.',
            'password.confirmed' => 'The two passwords do not match.',
            'email.required' => 'Enter your e-mail address.',
            'gender.required' => 'Choose a gender for your characters.',
            'gender.in' => 'Choose a gender for your characters.',
            'captcha.required' => 'Enter the characters shown in the image.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $server = $this->input('server');

        if (! is_string($server) || $server === '') {
            $this->merge(['server' => app(ServerRegistry::class)->default()->key]);
        }

        /*
         * Absent means Male.
         *
         * The account's sex decides nothing a player can see: rAthena carries
         * it on the account, but every character picks its own look, so the
         * question only ever added a step to the form. It is defaulted here
         * rather than hidden in the client so that the default belongs to the
         * API -- any client that stops asking gets the same answer, instead of
         * each one having to remember to send a value nobody chooses.
         *
         * A value that *is* sent still has to be a real one: an explicit 'S'
         * is a client sending nonsense, which is worth refusing.
         */
        $gender = $this->input('gender');

        $this->merge([
            'gender' => is_string($gender) && $gender !== ''
                ? mb_strtoupper($gender)
                : Gender::Male->value,
        ]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->verifyCaptcha($validator),
        ];
    }

    public function gender(): Gender
    {
        return Gender::from((string) $this->input('gender'));
    }

    /**
     * Refuse a host that is registering accounts in bulk.
     *
     * Keyed on the address only. There is no account to key on yet, and
     * keying on the submitted name would let somebody avoid the limit by
     * varying it -- which is exactly what bulk registration does.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->throttleSubmission(
            'register',
            [(string) $this->ip() => 5],
            600,
            'username',
        );
    }

    /**
     * Whether the client has to render a challenge for this form.
     */
    public function captchaRequired(): bool
    {
        return app(CaptchaGate::class)->requiredFor($this->captchaForm());
    }

    protected function captchaForm(): string
    {
        return CaptchaGate::REGISTRATION;
    }
}
