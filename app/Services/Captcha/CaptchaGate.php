<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Contracts\ChallengesHumanity;

/**
 * Whether a given form needs a CAPTCHA, and whether the one submitted passed.
 *
 * Form requests ask this rather than the driver directly, so that "the
 * operator has turned the CAPTCHA off for this form" is decided in one place
 * instead of in each set of rules.
 *
 * The forms are named after the legacy settings they came from: UseCaptcha
 * applied to registration, and a separate check applied it to sign-in.
 */
final readonly class CaptchaGate
{
    public const REGISTRATION = 'registration';

    public const LOGIN = 'login';

    public function __construct(private ChallengesHumanity $captcha) {}

    public function requiredFor(string $form): bool
    {
        return (bool) config("panel.captcha.on_{$form}", false);
    }

    /**
     * Whether the submission may proceed.
     *
     * Returns true when no challenge is required for this form, so a caller
     * can treat the result as "the CAPTCHA is satisfied" unconditionally.
     */
    public function passes(string $form, ?string $response, string $ipAddress): bool
    {
        if (! $this->requiredFor($form)) {
            return true;
        }

        return $this->captcha->verify($response, $ipAddress);
    }

    /**
     * Whether the challenge image comes from this application.
     *
     * The client needs this to choose between rendering our own image and
     * loading a third-party widget.
     */
    public function isSelfHosted(): bool
    {
        return $this->captcha->isSelfHosted();
    }

    /**
     * The site key a third-party widget needs, or null when the challenge is
     * self-hosted. Only ever the public half of the pair.
     */
    public function publicKey(): ?string
    {
        if ($this->captcha->isSelfHosted()) {
            return null;
        }

        $key = (string) config('panel.captcha.recaptcha.site_key', '');

        return $key === '' ? null : $key;
    }
}
