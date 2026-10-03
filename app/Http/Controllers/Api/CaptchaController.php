<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\ChallengesHumanity;
use App\Services\Captcha\NativeCaptcha;
use Illuminate\Http\Response;

/**
 * The challenge image, for the self-hosted CAPTCHA driver.
 *
 * Ports modules/captcha/index.php.
 */
final class CaptchaController
{
    public function __construct(private readonly ChallengesHumanity $captcha) {}

    public function show(): Response
    {
        /*
         * 404 rather than an error when reCAPTCHA is configured: there is no
         * image to serve, and a client asking for one is out of date rather
         * than broken.
         */
        abort_unless($this->captcha instanceof NativeCaptcha, 404);

        return response($this->captcha->issue(), 200, [
            'Content-Type' => 'image/png',
            /*
             * Never cached, by any of the three mechanisms that would
             * otherwise do it. A cached challenge is one image answered many
             * times, which is the same as no challenge -- and a shared proxy
             * caching it would serve one visitor's challenge to another.
             */
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
