<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A check that a form was submitted by a person.
 *
 * Two implementations exist, matching the legacy UseCaptcha + EnableReCaptcha
 * pair of booleans: one challenge the panel draws itself, and one delegated to
 * Google. Which is in use is config, so nothing calling this needs to know.
 */
interface ChallengesHumanity
{
    /**
     * Whether the submitted response is acceptable.
     *
     * A challenge is single use: a correct answer is consumed here, so the
     * same solved challenge cannot be replayed across several submissions.
     */
    public function verify(?string $response, string $ipAddress): bool;

    /**
     * Whether the client has to fetch a challenge from this application
     * before it can be answered.
     *
     * True for the challenge the panel draws, false for reCAPTCHA, which the
     * browser obtains from Google directly. The client reads this to decide
     * which widget to render.
     */
    public function isSelfHosted(): bool;
}
