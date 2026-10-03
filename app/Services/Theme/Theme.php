<?php

declare(strict_types=1);

namespace App\Services\Theme;

use JsonException;
use RuntimeException;

/**
 * An installed theme, as described by its theme.json.
 *
 * Deliberately inert: it knows what a theme declares about itself and where it
 * lives, and nothing about how the active one is chosen. That is
 * {@see ThemeService}'s job, so the rest of the application never has to ask
 * which theme is in play.
 */
final readonly class Theme
{
    /**
     * @param  string  $directory  Absolute path to the theme.
     * @param  string  $relativePath  Path from the project root, which is what
     *                                Vite entrypoints are named by.
     * @param  array<string, bool>  $supports
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public ?string $author,
        public string $description,
        public array $supports,
        /**
         * Which appearance this theme is designed around: 'light', 'dark', or
         * null to follow the visitor's operating system.
         *
         * A theme art-directed for one of them should say so, otherwise a
         * visitor whose system prefers the other sees it at its worst on a
         * first visit, and the appearance toggle disagrees with what is on
         * screen.
         */
        public ?string $defaultAppearance,
        public string $directory,
        public string $relativePath,
    ) {}

    /**
     * Read a theme from its directory.
     *
     * A theme without a readable, well-formed theme.json is not a theme. It is
     * rejected with the path in the message, because the usual cause is a
     * half-copied directory and a vague error sends the reader looking in the
     * wrong place.
     *
     * @throws RuntimeException
     */
    public static function fromDirectory(string $directory, string $relativePath): self
    {
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'theme.json';

        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException(
                "The theme at '{$relativePath}' has no readable theme.json."
            );
        }

        try {
            $manifest = json_decode(
                (string) file_get_contents($manifestPath),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                "The theme.json at '{$relativePath}' is not valid JSON: {$e->getMessage()}"
            );
        }

        if (! is_array($manifest)) {
            throw new RuntimeException("The theme.json at '{$relativePath}' must contain an object.");
        }

        $slug = basename($directory);

        foreach (['name', 'version'] as $required) {
            if (! isset($manifest[$required]) || ! is_string($manifest[$required]) || $manifest[$required] === '') {
                throw new RuntimeException(
                    "The theme.json at '{$relativePath}' is missing the required '{$required}' field."
                );
            }
        }

        /*
         * The directory name wins over a declared slug. The directory is what
         * APP_THEME names and what the asset and override paths are built
         * from, so a manifest claiming otherwise would be a trap.
         */
        if (isset($manifest['slug']) && $manifest['slug'] !== $slug) {
            throw new RuntimeException(sprintf(
                "The theme at '%s' declares slug '%s' but lives in a directory named '%s'. "
                .'The directory name is authoritative; rename one of them.',
                $relativePath,
                (string) $manifest['slug'],
                $slug,
            ));
        }

        $author = isset($manifest['author']) && is_string($manifest['author']) && $manifest['author'] !== ''
            ? $manifest['author']
            : null;

        return new self(
            slug: $slug,
            name: (string) $manifest['name'],
            version: (string) $manifest['version'],
            author: $author,
            description: isset($manifest['description']) ? (string) $manifest['description'] : '',
            supports: array_map(
                static fn (mixed $value): bool => (bool) $value,
                is_array($manifest['supports'] ?? null) ? $manifest['supports'] : [],
            ),
            defaultAppearance: in_array($manifest['default_appearance'] ?? null, ['light', 'dark'], true)
                ? (string) $manifest['default_appearance']
                : null,
            directory: $directory,
            relativePath: $relativePath,
        );
    }

    public function supports(string $feature): bool
    {
        return $this->supports[$feature] ?? false;
    }

    /**
     * The theme's Vite entrypoint, or null when it ships no stylesheet.
     *
     * Returned as a project-relative path because that is how Vite names an
     * entry, and how Blade's @vite directive looks it up in the manifest.
     */
    public function stylesheet(): ?string
    {
        $relative = $this->relativePath.'/styles/theme.css';

        return is_file($this->directory.'/styles/theme.css') ? $relative : null;
    }

    public function hasAssets(): bool
    {
        return is_dir($this->directory.DIRECTORY_SEPARATOR.'assets');
    }

    /**
     * The subset handed to the browser.
     *
     * Only what the client genuinely needs to render. Paths are left out: the
     * filesystem layout of the server is not the browser's business.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'supports' => $this->supports,
            'defaultAppearance' => $this->defaultAppearance,
        ];
    }
}
