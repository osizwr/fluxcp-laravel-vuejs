<?php

declare(strict_types=1);

namespace App\Http\Requests\Account\Concerns;

use App\Services\Captcha\CaptchaGate;
use Illuminate\Contracts\Validation\Validator;

/**
 * Adds the CAPTCHA check to a form request.
 *
 * It runs in `after`, not as a rule, so that it is evaluated once per
 * submission and only after the cheap rules have had their say. That ordering
 * matters because a challenge is consumed when it is checked: verifying it
 * before knowing whether the rest of the form is even well formed would burn
 * the challenge on a submission that was going to be rejected anyway, and the
 * person would be asked to solve a second image for a typo in their e-mail
 * address.
 */
trait VerifiesCaptcha
{
    /**
     * Which form this is, for the `panel.captcha.on_*` switches.
     */
    abstract protected function captchaForm(): string;

    /**
     * @return array<string, mixed>
     */
    protected function captchaRules(): array
    {
        if (! app(CaptchaGate::class)->requiredFor($this->captchaForm())) {
            return [];
        }

        return [
            'captcha' => ['required', 'string', 'max:64'],
        ];
    }

    protected function verifyCaptcha(Validator $validator): void
    {
        $gate = app(CaptchaGate::class);

        if (! $gate->requiredFor($this->captchaForm())) {
            return;
        }

        // Nothing to check yet if the field itself failed, and checking anyway
        // would consume the challenge.
        if ($validator->errors()->has('captcha')) {
            return;
        }

        $response = $this->input('captcha');

        if (! $gate->passes(
            $this->captchaForm(),
            is_string($response) ? $response : null,
            (string) $this->ip(),
        )) {
            $validator->errors()->add('captcha', trans('accounts.captcha.incorrect'));
        }
    }
}
