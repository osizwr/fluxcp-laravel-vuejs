<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ChallengesHumanity;
use App\Services\Captcha\NativeCaptcha;
use App\Services\Captcha\ReCaptcha;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerCaptcha();
    }

    /**
     * Bind the CAPTCHA driver the operator selected.
     *
     * An unrecognised driver name throws rather than silently falling back.
     * Falling back to "no challenge" would turn a typo in .env into open
     * registration, and falling back to the native driver would be just as
     * surprising for an operator who meant to use reCAPTCHA.
     */
    private function registerCaptcha(): void
    {
        $this->app->singleton(ChallengesHumanity::class, function ($app): ChallengesHumanity {
            $driver = (string) config('panel.captcha.driver', 'native');

            return match ($driver) {
                'native' => $app->make(NativeCaptcha::class),
                'recaptcha' => $app->make(ReCaptcha::class),
                default => throw new InvalidArgumentException(
                    "Unknown CAPTCHA driver [{$driver}]. PANEL_CAPTCHA_DRIVER must be 'native' or 'recaptcha'.",
                ),
            };
        });
    }
}
