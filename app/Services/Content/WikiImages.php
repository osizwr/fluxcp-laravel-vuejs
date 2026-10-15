<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where the dashboard's images go, and what they are called when they get
 * there.
 *
 * Two kinds, with different rules, and the difference is what each one is for:
 *
 *   a body image     anything an author drops into a page mid-sentence. Kept
 *                    in the format it arrived in, because it is their
 *                    screenshot and re-encoding it is not this panel's call.
 *
 *   a section image  the art on a tile on /wiki. One fixed square, converted
 *                    to WebP on the way in -- because here the panel *is*
 *                    making the call: nine of these load on the index at once,
 *                    on a page that is often somebody's first, and a 2MB PNG
 *                    tile is a second of blank grid on a phone.
 *
 * Both are named from a hash of the bytes that end up on disk. See
 * WikiImageController for why a name the browser chose never reaches the
 * filesystem.
 */
final readonly class WikiImages
{
    public function __construct(private ConfigRepository $config) {}

    public function disk(): Filesystem
    {
        return Storage::disk((string) $this->config->get('wiki.uploads.disk', 'public'));
    }

    /**
     * Keep a file as it is, and answer with the URL to write into a page.
     */
    public function store(UploadedFile $file): string
    {
        $directory = $this->directory('wiki.uploads.directory', 'wiki');
        $extension = $file->guessExtension() ?? 'bin';

        $name = substr(hash_file('sha256', $file->getRealPath()) ?: '', 0, 32).'.'.$extension;

        $path = $this->join($directory, $name);

        if (! $this->disk()->exists($path)) {
            $this->disk()->putFileAs($directory, $file, $name, 'public');
        }

        return $this->url($path);
    }

    /**
     * Re-encode a section's art as WebP, and answer with its URL.
     *
     * The format is the whole point of the conversion: WebP is a third to a
     * half of the PNG an operator will usually be uploading, at a size nobody
     * can see the difference at, and the tiles are what the index spends its
     * bytes on.
     *
     * GD rather than a library, because GD is already there -- this panel adds
     * a dependency when it needs one and not to save twenty lines.
     *
     * @throws RuntimeException when the file cannot be decoded. The request
     *                          has already established that it is an image, so
     *                          reaching this means a format GD was not built
     *                          with, which is worth saying out loud rather
     *                          than storing something broken.
     */
    public function storeSectionImage(UploadedFile $file): string
    {
        $webp = $this->toWebp((string) file_get_contents($file->getRealPath()));

        $directory = $this->sectionDirectory();
        $name = substr(hash('sha256', $webp), 0, 32).'.webp';

        $path = $this->join($directory, $name);

        // Identical bytes mean the identical image: writing it again would
        // produce the same file.
        if (! $this->disk()->exists($path)) {
            $this->disk()->put($path, $webp, 'public');
        }

        return $this->url($path);
    }

    /**
     * Whether a string is a URL this class produced for a section.
     *
     * What a section's `image` column is allowed to hold. Not a general URL:
     * a free-text field there would let the dashboard point every tile on the
     * index at somebody else's server, which hands that server the IP address
     * of every visitor who loads the page and takes the index down with it
     * when it goes.
     */
    public function isSectionImage(string $url): bool
    {
        $prefix = $this->url($this->join($this->sectionDirectory(), ''));

        return str_starts_with($url, $prefix)
            && preg_match('/^[0-9a-f]{32}\.webp$/', substr($url, strlen($prefix))) === 1;
    }

    /**
     * The URL for a stored path, with this site's own origin taken off.
     *
     * What goes into the page, or into a row, outlives the hostname in front
     * of it: a body written on a staging domain and copied to production, or
     * an operator who moves to HTTPS, would otherwise be left pointing at the
     * old one. A disk served from somewhere else -- S3, a CDN -- keeps its
     * host, because there the host is the point.
     */
    public function url(string $path): string
    {
        $url = $this->disk()->url($path);
        $base = rtrim((string) $this->config->get('app.url'), '/');

        return $base !== '' && str_starts_with($url, $base.'/')
            ? substr($url, strlen($base))
            : $url;
    }

    private function toWebp(string $data): string
    {
        $image = @imagecreatefromstring($data);

        if ($image === false) {
            throw new RuntimeException('That image could not be read on this server.');
        }

        /*
         * Three calls that only matter for the formats people actually
         * upload: a palette PNG or GIF has to become true colour before it
         * can be written as WebP, and transparency is lost unless blending is
         * off and saving alpha is on.
         */
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, max(1, min(100, (int) $this->config->get(
            'wiki.uploads.section_image.quality',
            82,
        ))));
        $webp = (string) ob_get_clean();

        imagedestroy($image);

        if ($webp === '') {
            throw new RuntimeException('That image could not be converted on this server.');
        }

        return $webp;
    }

    private function sectionDirectory(): string
    {
        return $this->directory('wiki.uploads.section_image.directory', 'wiki/sections');
    }

    private function directory(string $key, string $fallback): string
    {
        return trim((string) $this->config->get($key, $fallback), '/');
    }

    private function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory.'/'.$name;
    }
}
