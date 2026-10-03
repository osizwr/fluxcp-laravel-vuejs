<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Theme
|--------------------------------------------------------------------------
|
| Which visual skin the panel wears. A theme is presentation only: it may
| restyle and restructure the interface, and it may not contain business
| logic, database access or authorisation decisions. The same backend serves
| every theme.
|
| Note the distinction from config/game.php, which answers "what server is
| this?" rather than "how does it look?". Changing the game name must not
| require touching a theme, and changing the theme must not change the game
| name.
|
| See docs/THEMING.md.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Active theme
    |--------------------------------------------------------------------------
    |
    | The directory name of the theme to use, under the path below. Resolved
    | at request time, so switching this takes effect on the next request
    | without rebuilding -- provided the theme was present when the frontend
    | was last built, since Vite has to have seen its files.
    |
    */

    'active' => env('APP_THEME', 'fantasy'),

    /*
    |--------------------------------------------------------------------------
    | Fallback theme
    |--------------------------------------------------------------------------
    |
    | Used when the active theme cannot be found. The fallback is reported as a
    | warning rather than applied silently, so a typo in APP_THEME is visible
    | instead of merely puzzling.
    |
    | Set to null to disable the fallback, in which case a missing theme throws.
    |
    */

    'fallback' => env('APP_THEME_FALLBACK', 'fantasy'),

    /*
    |--------------------------------------------------------------------------
    | Theme directory
    |--------------------------------------------------------------------------
    |
    | Where themes live, relative to the project root. Themes are kept inside
    | resources/ because Vite must be able to see them at build time; a path
    | outside the project would not be bundled.
    |
    */

    'path' => env('APP_THEME_PATH', 'resources/themes'),

    /*
    |--------------------------------------------------------------------------
    | Strict resolution
    |--------------------------------------------------------------------------
    |
    | When true, a missing theme throws even if a fallback is configured. Worth
    | enabling in CI so a deployment cannot quietly ship the wrong skin.
    |
    */

    'strict' => (bool) env('APP_THEME_STRICT', false),

];
