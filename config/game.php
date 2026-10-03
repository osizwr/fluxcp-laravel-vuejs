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

    /*
    |--------------------------------------------------------------------------
    | Announcement bar
    |--------------------------------------------------------------------------
    |
    | A single operator-authored notice shown above the masthead. Content, not
    | theme: the theme decides how it looks, this decides whether there is one
    | and what it says.
    |
    | Off by default. An announcement bar with nothing to announce is a
    | placeholder, and a placeholder is worse than an absent section.
    |
    | 'tone' lets a theme distinguish a cheerful event from a maintenance
    | warning. The dismissal id is derived from the message on the server, so
    | editing the message makes it reappear for everyone who dismissed the old
    | one -- which is what an operator means by changing it.
    |
    */

    'announcement' => [
        'enabled' => (bool) env('GAME_ANNOUNCEMENT_ENABLED', false),
        'message' => env('GAME_ANNOUNCEMENT_MESSAGE', ''),
        'url' => env('GAME_ANNOUNCEMENT_URL'),
        'label' => env('GAME_ANNOUNCEMENT_LABEL'),
        // 'info', 'event' or 'maintenance'.
        'tone' => env('GAME_ANNOUNCEMENT_TONE', 'info'),
        'dismissible' => (bool) env('GAME_ANNOUNCEMENT_DISMISSIBLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | What the server offers, for the feature section on the front page.
    |
    | Entirely operator-owned, because the panel cannot know what a given
    | rAthena install has enabled. The defaults below are limited to things
    | this panel demonstrably provides and can link to -- they are not claims
    | about game mechanics that may not exist on your server.
    |
    | Replace them. A 'url' is optional, and an entry without one renders as
    | plain text rather than a dead link.
    |
    */

    'features' => [
        [
            'title' => 'Competitive rankings',
            'description' => 'Level and wealth ladders, updated from live character data.',
            'url' => '/rankings/level',
        ],
        [
            'title' => 'Who is online',
            'description' => 'See who is in the world right now, searchable by name.',
            'url' => '/who-is-online',
        ],
        [
            'title' => 'War of Emperium',
            'description' => 'Siege schedule and castle ownership for every world.',
            'url' => null,
        ],
    ],

];
