<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ChallengesHumanity;
use App\Services\Captcha\NativeCaptcha;
use App\Services\Captcha\ReCaptcha;
use App\Services\Content\WikiCategoryOverrides;
use App\Services\Content\WikiOverrides;
use App\Services\Settings\PanelSettings;
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

        /*
         * One instance per request, because it memoises the override table on
         * first read. A fresh instance per injection would turn ClientBootstrap
         * asking for four settings into four identical queries.
         */
        $this->app->singleton(PanelSettings::class);

        /*
         * Both of the wiki's overlays, one instance per request for the same
         * reason and for a second one: the wiki controllers flush them after a
         * write, and a flush on a copy nobody else holds -- WikiLibrary holds
         * the one that matters -- would not be a flush at all.
         */
        $this->app->singleton(WikiOverrides::class);
        $this->app->singleton(WikiCategoryOverrides::class);
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
