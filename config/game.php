<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Game branding
|--------------------------------------------------------------------------
|
| What server this panel fronts. Kept separate from config/theme.php on
| purpose: this answers "what game is this?", the theme answers "how does it
| look?". An operator renaming their server should not have to touch a theme,
| and installing a theme should not rename their server.
|
| Everything here is public -- it is handed to the browser -- so no secret
| belongs in this file.
|
| Note that this is not config/app.php's APP_NAME. That stays Laravel's
| internal application name, used for things like the mail "from" name. This
| is the brand shown to players.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Names
    |--------------------------------------------------------------------------
    */

    'name' => env('GAME_NAME', 'Ragnarok Online'),

    /*
     * Used where the full name will not fit: the mobile masthead, the browser
     * tab on narrow screens, the generated mark.
     */
    'short_name' => env('GAME_SHORT_NAME', 'RO'),

    'description' => env('GAME_DESCRIPTION', 'Fantasy MMORPG'),

    'version' => env('GAME_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Imagery
    |--------------------------------------------------------------------------
    |
    | Both optional. When no logo is configured the active theme draws its own
    | mark from the short name, so the panel never shows a broken image.
    |
    | A URL rather than a path, so it can point at published theme assets
    | (`php artisan theme:publish` exposes them under /themes/<slug>/...), at
    | your own public/ directory, or at a CDN.
    |
    */

    'logo' => env('GAME_LOGO'),

    'favicon' => env('GAME_FAVICON'),

    /*
    |--------------------------------------------------------------------------
    | Outbound links
    |--------------------------------------------------------------------------
    |
    | Navigation only renders a link that is configured, so an unset value
    | means the item is absent rather than dead.
    |
    */

    'links' => [
        'website' => env('GAME_WEBSITE_URL'),
        'downloads' => env('GAME_DOWNLOADS_URL'),
        'discord' => env('GAME_DISCORD_URL'),
        'forum' => env('GAME_FORUM_URL'),
    ],

];
