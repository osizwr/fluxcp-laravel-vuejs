<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Throwable;

/**
 * Guild emblems, decoded to PNG.
 *
 * rAthena stores the emblem as a gzip-compressed 24-bit BMP, written to the
 * database as a hex string. Getting from the column to an image is three
 * steps -- hex to binary, inflate, decode -- and a failure at any of them
 * means the guild has no usable emblem rather than that something is broken,
 * because the client uploads whatever it likes.
 *
 * ---------------------------------------------------------------------------
 * Magenta is transparent
 * ---------------------------------------------------------------------------
 *
 * The RO client treats pure magenta (0xFF00FF) as the transparent colour in an
 * emblem. BMP has no alpha channel, so without translating it an emblem
 * renders with a bright pink background on every page that shows one. That
 * conversion is the main reason this is not simply "serve the BMP".
 *
 * ---------------------------------------------------------------------------
 * What changed from the legacy
 * ---------------------------------------------------------------------------
 *
 * FluxCP shipped its own `imagecreatefrombmpstring()` because PHP had no BMP
 * reader at the time. PHP has had `imagecreatefrombmp()` since 7.2, so the
 * hand-written decoder is dropped rather than ported.
 *
 * The legacy also cached decoded emblems as files under its data directory,
 * creating the directory with mode 0777 when `RequireOwnership` was off. This
 * caches through Laravel's cache instead, so there is no world-writable
 * directory and no path assembled from request parameters.
 */
final readonly class GuildEmblemService
{
    /** The colour the client renders as transparent. */
    private const TRANSPARENT = [0xFF, 0x00, 0xFF];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
    ) {}

    /**
     * A guild's emblem as PNG data, or null when it has none that can be read.
     */
    public function png(int $guildId): ?string
    {
        $cacheSeconds = max(0, (int) config('panel.guilds.emblem_cache_seconds', 600));

        $key = sprintf(
            'guild.emblem.%s.%d',
            $this->servers->currentCharMapServer()->connectionName(),
            $guildId,
        );

        if ($cacheSeconds === 0) {
            return $this->render($guildId);
        }

        /*
         * `flexible` would be nicer but the value may legitimately be null --
         * a guild with no emblem -- and remembering null is the point: it
         * stops every page view re-reading and re-failing on the same blob.
         */
        return cache()->remember($key, $cacheSeconds, fn (): ?string => $this->render($guildId));
    }

    private function render(int $guildId): ?string
    {
        $row = $this->connections
            ->connection($this->servers->currentCharMapServer()->connectionName())
            ->table('guild')
            ->select('emblem_len', 'emblem_data')
            ->where('guild_id', $guildId)
            ->first();

        if ($row === null || (int) ($row->emblem_len ?? 0) === 0) {
            return null;
        }

        $bitmap = $this->decode((string) $row->emblem_data);

        return $bitmap === null ? null : $this->toPng($bitmap);
    }

    /**
     * Hex string to BMP data.
     *
     * Every step is guarded. The column holds whatever the game client
     * uploaded, so malformed or truncated data is expected rather than
     * exceptional, and a guild with a broken emblem must not break the page
     * listing it.
     */
    private function decode(string $hex): ?string
    {
        $hex = trim($hex);

        if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1 || strlen($hex) % 2 !== 0) {
            return null;
        }

        $binary = @hex2bin($hex);

        if ($binary === false || $binary === '') {
            return null;
        }

        // Suppressed rather than caught: gzuncompress raises a warning and
        // returns false on bad data, and bad data is routine here.
        $inflated = @gzuncompress($binary);

        return $inflated === false || $inflated === '' ? null : $inflated;
    }

    /**
     * BMP data to PNG, with magenta turned into transparency.
     */
    private function toPng(string $bitmap): ?string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException(
                'Rendering guild emblems needs the GD extension. Install it, or set '
                .'PANEL_GUILD_EMBLEMS=false to stop the panel offering them.'
            );
        }

        try {
            $source = @imagecreatefromstring($bitmap);
        } catch (Throwable) {
            return null;
        }

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $canvas = imagecreatetruecolor($width, $height);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        [$tr, $tg, $tb] = self::TRANSPARENT;

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($source, $x, $y);

                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($r === $tr && $g === $tg && $b === $tb) {
                    continue;
                }

                imagesetpixel($canvas, $x, $y, imagecolorallocate($canvas, $r, $g, $b));
            }
        }

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $png === '' ? null : $png;
    }
}
