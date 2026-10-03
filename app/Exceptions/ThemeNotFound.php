<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The configured theme does not exist.
 *
 * Thrown rather than swallowed: a panel silently wearing the wrong skin is a
 * worse outcome than one that refuses to start and says why. The message names
 * the themes that *are* installed, because the cause is almost always a typo
 * or a directory that was never copied.
 */
final class ThemeNotFound extends RuntimeException
{
    /**
     * @param  list<string>  $installed
     */
    public static function named(string $slug, string $path, array $installed): self
    {
        $available = $installed === []
            ? 'No themes are installed at all.'
            : 'Installed themes: '.implode(', ', $installed).'.';

        return new self(sprintf(
            "The theme '%s' was not found in '%s'. %s "
            .'Set APP_THEME to an installed theme, or APP_THEME_PATH if your themes live elsewhere.',
            $slug,
            $path,
            $available,
        ));
    }
}
