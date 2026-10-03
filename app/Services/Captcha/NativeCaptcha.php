<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Contracts\ChallengesHumanity;
use GdImage;
use Illuminate\Contracts\Session\Session;
use RuntimeException;

/**
 * A challenge the panel draws itself, answered from the session.
 *
 * Replaces FluxCP's Flux_Captcha. Three differences worth naming:
 *
 *   - The answer is stored as a digest, not as the text. The session store is
 *     a database table here, and a table of readable CAPTCHA answers is a
 *     thing worth not having.
 *
 *   - A challenge expires, and is consumed when answered. The legacy one sat
 *     in the session indefinitely and was not cleared on use, so one solved
 *     image could be replayed for as long as the session lasted.
 *
 *   - Glyphs are drawn with GD's built-in fonts rather than a TrueType file,
 *     so there is no font to ship, licence or lose. The distortion comes from
 *     per-glyph rotation and scaling instead.
 *
 * This is a speed bump, not a guarantee. Any hand-rolled image CAPTCHA is
 * solvable by a determined attacker with off-the-shelf OCR; it exists to stop
 * casual scripted registration. An operator who needs more should set
 * PANEL_CAPTCHA_DRIVER=recaptcha.
 */
final class NativeCaptcha implements ChallengesHumanity
{
    /**
     * Where the pending answer lives in the session.
     */
    private const SESSION_KEY = 'captcha.pending';

    public function __construct(private readonly Session $session) {}

    /**
     * Draw a new challenge and remember its answer.
     *
     * Any previous unanswered challenge is replaced, so only the most recently
     * issued image is ever accepted.
     *
     * @return string PNG image data.
     */
    public function issue(): string
    {
        $answer = $this->randomAnswer();

        $this->session->put(self::SESSION_KEY, [
            'digest' => $this->digest($answer),
            'expires_at' => now()->addSeconds($this->expirySeconds())->getTimestamp(),
        ]);

        return $this->draw($answer);
    }

    public function verify(?string $response, string $ipAddress): bool
    {
        $pending = $this->session->get(self::SESSION_KEY);

        /*
         * Consumed whatever the outcome. A wrong answer burns the challenge
         * too, which is what stops an attacker brute-forcing one image: the
         * next attempt has to fetch a new one.
         */
        $this->session->forget(self::SESSION_KEY);

        if (! is_array($pending) || $response === null || $response === '') {
            return false;
        }

        $digest = $pending['digest'] ?? null;
        $expiresAt = $pending['expires_at'] ?? 0;

        if (! is_string($digest) || now()->getTimestamp() > (int) $expiresAt) {
            return false;
        }

        return hash_equals($digest, $this->digest($response));
    }

    public function isSelfHosted(): bool
    {
        return true;
    }

    /**
     * Whether a challenge is currently outstanding and still answerable.
     *
     * Used by the client bootstrap so a form can tell whether it needs to
     * fetch an image.
     */
    public function hasOutstandingChallenge(): bool
    {
        $pending = $this->session->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            return false;
        }

        return now()->getTimestamp() <= (int) ($pending['expires_at'] ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The answer
    |--------------------------------------------------------------------------
    */

    private function randomAnswer(): string
    {
        $alphabet = (string) config('panel.captcha.native.characters', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789');
        $length = max(3, min(10, (int) config('panel.captcha.native.length', 5)));

        if ($alphabet === '') {
            throw new RuntimeException(
                'panel.captcha.native.characters is empty, so no challenge can be generated.',
            );
        }

        $characters = mb_str_split($alphabet);
        $answer = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int, not rand(): the answer is a secret for its lifetime,
            // and a predictable one is no challenge at all.
            $answer .= $characters[random_int(0, count($characters) - 1)];
        }

        return $answer;
    }

    /**
     * Compared case-insensitively, because reading case off a distorted image
     * is guesswork and the legacy panel compared with strtolower() too.
     */
    private function digest(string $value): string
    {
        return hash('sha256', mb_strtoupper(trim($value)));
    }

    private function expirySeconds(): int
    {
        return max(30, (int) config('panel.captcha.native.expires_after_seconds', 600));
    }

    /*
    |--------------------------------------------------------------------------
    | The image
    |--------------------------------------------------------------------------
    */

    /**
     * @return string PNG image data.
     */
    private function draw(string $answer): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException(
                'The native CAPTCHA driver needs the GD extension. Install it, '
                .'or set PANEL_CAPTCHA_DRIVER=recaptcha, or turn the CAPTCHA off.',
            );
        }

        $width = max(80, (int) config('panel.captcha.native.width', 200));
        $height = max(40, (int) config('panel.captcha.native.height', 70));

        $canvas = imagecreatetruecolor($width, $height);

        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 248, 248, 250));

        $this->drawNoise($canvas, $width, $height);
        $this->drawGlyphs($canvas, $answer, $width, $height);
        $this->drawInterference($canvas, $width, $height);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);

        return $png;
    }

    /**
     * Speckle, drawn under the text so it does not obscure it.
     */
    private function drawNoise(GdImage $canvas, int $width, int $height): void
    {
        for ($i = 0, $dots = intdiv($width * $height, 40); $i < $dots; $i++) {
            $grey = random_int(200, 235);

            imagesetpixel(
                $canvas,
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                imagecolorallocate($canvas, $grey, $grey, $grey),
            );
        }
    }

    /**
     * Each character on its own small canvas, rotated, then scaled up onto the
     * main one.
     *
     * Drawing glyph by glyph is what allows per-character rotation and
     * baseline jitter without a TrueType font, which is the whole reason this
     * does not need a font file.
     */
    private function drawGlyphs(GdImage $canvas, string $answer, int $width, int $height): void
    {
        $characters = mb_str_split($answer);
        $count = max(1, count($characters));

        // Built-in font 5 is 9x15 pixels, the largest GD provides.
        $glyphWidth = 9;
        $glyphHeight = 15;

        $cellWidth = intdiv($width, $count + 1);
        $targetHeight = (int) ($height * 0.62);
        $targetWidth = (int) ($glyphWidth * ($targetHeight / $glyphHeight));

        foreach ($characters as $index => $character) {
            $tile = imagecreatetruecolor($glyphWidth + 4, $glyphHeight + 4);

            // Transparent so the rotation does not paint a box over the noise.
            imagealphablending($tile, false);
            imagesavealpha($tile, true);
            imagefill($tile, 0, 0, imagecolorallocatealpha($tile, 0, 0, 0, 127));

            imagestring(
                $tile,
                5,
                2,
                2,
                $character,
                imagecolorallocate(
                    $tile,
                    random_int(20, 90),
                    random_int(20, 90),
                    random_int(60, 140),
                ),
            );

            $rotated = imagerotate(
                $tile,
                (float) random_int(-28, 28),
                imagecolorallocatealpha($tile, 0, 0, 0, 127),
            );

            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);

            $x = intdiv($cellWidth, 2) + ($index * $cellWidth) + random_int(-3, 3);
            $y = (int) (($height - $targetHeight) / 2) + random_int(-5, 5);

            imagecopyresampled(
                $canvas,
                $rotated,
                $x,
                $y,
                0,
                0,
                $targetWidth,
                $targetHeight,
                imagesx($rotated),
                imagesy($rotated),
            );

            imagedestroy($tile);
            imagedestroy($rotated);
        }
    }

    /**
     * Lines across the text, which is what defeats naive per-glyph
     * segmentation.
     */
    private function drawInterference(GdImage $canvas, int $width, int $height): void
    {
        for ($i = 0; $i < 4; $i++) {
            imageline(
                $canvas,
                random_int(0, intdiv($width, 4)),
                random_int(0, $height),
                random_int(intdiv($width, 2), $width),
                random_int(0, $height),
                imagecolorallocate($canvas, random_int(120, 190), random_int(120, 190), random_int(150, 210)),
            );
        }
    }
}
