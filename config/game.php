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
        'donate' => env('GAME_DONATE_URL'),
        'facebook' => env('GAME_FACEBOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | The main navigation
    |--------------------------------------------------------------------------
    |
    | What the masthead offers, in order. Configuration for the same reason the
    | footer's groups are: these name sections this server has, not decisions
    | about how the masthead looks, so they must survive a change of skin.
    |
    | Leave this empty and every theme falls back to the panel's own built-in
    | navigation -- Status, Rankings, Items, Monsters and the rest -- which,
    | unlike the list below, is translated into each locale the panel ships.
    | Labels written here are literal, so a server running in more than one
    | language either keeps the built-in list or accepts an untranslated
    | masthead.
    |
    | Entries take 'to' (an internal route), 'url' (an external address, which
    | wins over 'to'), or neither -- a section that has no page yet, drawn in
    | place but not as a link.
    |
    */

    'nav' => [
        ['label' => 'Home', 'to' => '/'],
        ['label' => 'News & Updates', 'url' => env('GAME_NEWS_URL')],
        ['label' => 'Download', 'to' => '/downloads', 'url' => env('GAME_DOWNLOADS_URL')],
        /*
         * The panel's own wiki, unless GAME_WIKI_URL points somewhere else --
         * a configured URL wins over the route, the same way Download's does.
         * An operator who keeps their guide on a GitBook sets that variable
         * and the internal pages stop being linked; one who does not gets
         * /wiki. See config/wiki.php.
         */
        ['label' => 'Wiki', 'to' => '/wiki', 'url' => env('GAME_WIKI_URL')],
        ['label' => 'Marketplace', 'url' => env('GAME_MARKETPLACE_URL')],
        ['label' => 'Top Up', 'url' => env('GAME_TOPUP_URL')],
        ['label' => 'Socials', 'url' => env('GAME_SOCIAL_URL')],
    ],

    /*
    |--------------------------------------------------------------------------
    | The footer
    |--------------------------------------------------------------------------
    |
    | Which links the footer carries, in which groups, and in what order.
    |
    | Configuration rather than theme markup, because these are features of the
    | server rather than decisions about how it looks: a marketplace is either
    | something this server has or it is not, and that answer must survive
    | switching skins. The active theme decides how the groups are drawn --
    | Skyward splits each into two balanced columns -- not what is in them.
    |
    | Each entry is one of:
    |
    |   'to'    an internal route, rendered as a RouterLink
    |   'url'   an external address, rendered as a new-tab anchor
    |   neither a page that does not exist yet, rendered as an inert item
    |
    | That last case is why the shape is a list rather than a filter. The
    | default set below mirrors a full-featured server, including sections this
    | panel has no routes for yet; give one a 'to' or a 'url' and it becomes a
    | real link without touching a component.
    |
    */

    'footer' => [

        /*
         * Each group is drawn as a two-column grid filled left-to-right, so
         * the order here reads across the rows rather than down the columns:
         *
         *     Home         Accounts
         *     Information  Rankings
         *
         * An entry is one of:
         *
         *   'to'    an internal route, rendered as a RouterLink
         *   'url'   an external address, rendered as a new-tab anchor
         *           (set, it wins over 'to')
         *   neither a page that does not exist yet, rendered as an inert item
         */
        'groups' => [
            [
                'heading' => 'Navigate',
                'links' => [
                    ['label' => 'Home', 'to' => '/'],
                    ['label' => 'Accounts', 'to' => '/account'],
                    ['label' => 'Wiki', 'to' => '/wiki', 'url' => env('GAME_WIKI_URL')],
                    ['label' => 'Information', 'url' => env('GAME_INFORMATION_URL')],
                    ['label' => 'Rankings', 'to' => '/rankings/level'],
                    ['label' => 'Marketplace', 'url' => env('GAME_MARKETPLACE_URL')],
                    ['label' => 'Auctions', 'url' => env('GAME_AUCTIONS_URL')],
                    ['label' => 'Streamers', 'url' => env('GAME_STREAMERS_URL')],
                ],
            ],
            [
                'heading' => 'Account',
                'links' => [
                    ['label' => 'Register', 'to' => '/register'],
                    ['label' => 'Login', 'to' => '/sign-in'],
                    ['label' => 'Downloads', 'to' => '/downloads', 'url' => env('GAME_DOWNLOADS_URL')],
                    ['label' => 'Donate', 'url' => env('GAME_DONATE_URL')],
                ],
            ],
        ],

        /*
         * The social buttons. Rendered in this order, and each keeps its disc
         * whether or not its URL is set so the row holds its shape while a
         * server is still being stood up.
         */
        'socials' => [
            ['network' => 'discord', 'url' => env('GAME_DISCORD_URL')],
            ['network' => 'facebook', 'url' => env('GAME_FACEBOOK_URL')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal and credits
    |--------------------------------------------------------------------------
    |
    | The footer's small print. Configuration rather than markup for the same
    | reason the name is: the wording is specific to the server that is running
    | and to whoever runs it, so a theme must not carry it. A test asserts that
    | no frontend file writes the game's name in, and the disclaimer is exactly
    | the sentence that would be tempted to.
    |
    | Both are optional, and an unset value renders nothing at all rather than
    | an empty line.
    |
    */

    'legal' => [

        /*
         * The affiliation notice. Most private servers need one; the exact
         * wording is the operator's call, and a default that named a rights
         * holder for them would be putting words in their mouth.
         *
         * `:name` is replaced with the game name, so the notice stays correct
         * after a rebrand.
         */
        'disclaimer' => env(
            'GAME_DISCLAIMER',
            ':name is an independent community server and is not affiliated with or endorsed '
            .'by Gravity Co., Ltd. Ragnarok Online is a trademark of its respective owner.',
        ),

        /*
         * The holder named in the copyright line. Defaults to the game name,
         * which is right for most operators and wrong for the ones running
         * under a company, hence the override.
         */
        'copyright' => env('GAME_COPYRIGHT'),

        /*
         * An optional "Designed by" credit -- a studio, a host, a volunteer.
         * The name is what renders; the URL only makes it a link.
         */
        'credit' => [
            'name' => env('GAME_CREDIT_NAME'),
            'url' => env('GAME_CREDIT_URL'),
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | What /downloads offers: the packages themselves, the specifications
    | somebody should check before starting one, and what to do once the file
    | has arrived.
    |
    | Operator-owned for the same reason the feature list is -- the panel
    | cannot know what this server ships, where it is hosted, or which client
    | it was built against. Every section renders only what is configured, so
    | an unset one is an absent section rather than an empty heading, and a
    | page with nothing at all configured says so instead of pretending.
    |
    | Setting GAME_DOWNLOADS_URL points the navigation somewhere else entirely
    | and leaves this page in place at /downloads, reachable but unlinked.
    |
    */

    'downloads' => [

        /*
         * An optional line above the packages -- a mirror that is down, the
         * date of the last rebuild, a patch everyone needs. Null renders
         * nothing rather than an empty band.
         */
        'notice' => env('GAME_DOWNLOADS_NOTICE'),

        /*
         * The packages on offer, in order.
         *
         * 'platform' is one of windows, macos, linux, android or ios. It picks
         * the icon and the platform label; anything else is drawn without one
         * rather than guessed at.
         *
         * 'mirrors' are the actual files, and a mirror without a URL is
         * dropped. A package left with no mirror at all is still drawn, marked
         * as not yet available -- the shape of the page stays visible while a
         * server is being stood up, which is exactly when these are empty.
         *
         * 'notes' are the caveats that belong to one package rather than to
         * the page: how to install an APK, which build a platform has not got.
         *
         * 'badge' is a short word beside the name -- New, Beta, Optional.
         */
        'clients' => [
            [
                'name' => 'Full client',
                'platform' => 'windows',
                'badge' => null,
                'version' => env('GAME_CLIENT_VERSION'),
                'size' => env('GAME_CLIENT_SIZE'),
                'updated' => env('GAME_CLIENT_UPDATED'),
                'description' => 'Everything needed to play, in one archive. Start here if you have not installed before.',
                'mirrors' => [
                    ['label' => 'Google Drive', 'url' => env('GAME_CLIENT_GOOGLE_DRIVE_URL')],
                    ['label' => 'MediaFire', 'url' => env('GAME_CLIENT_MEDIAFIRE_URL')],
                    ['label' => 'Mega', 'url' => env('GAME_CLIENT_MEGA_URL')],
                    ['label' => 'Direct link', 'url' => env('GAME_CLIENT_DIRECT_URL')],
                ],
                'notes' => [],
            ],
            [
                'name' => 'Android',
                'platform' => 'android',
                'badge' => null,
                'version' => env('GAME_ANDROID_VERSION'),
                'size' => env('GAME_ANDROID_SIZE'),
                'updated' => env('GAME_ANDROID_UPDATED'),
                'description' => 'The mobile build, installed from the file rather than from a store.',
                'mirrors' => [
                    ['label' => 'APK', 'url' => env('GAME_ANDROID_APK_URL')],
                ],
                'notes' => [
                    'Android asks whether to allow installing from your browser. That permission is only needed for this one install.',
                ],
            ],
            [
                'name' => 'iOS',
                'platform' => 'ios',
                'badge' => null,
                'version' => env('GAME_IOS_VERSION'),
                'size' => env('GAME_IOS_SIZE'),
                'updated' => env('GAME_IOS_UPDATED'),
                'description' => 'The iPhone and iPad build, installed from the file rather than from the App Store.',
                'mirrors' => [
                    ['label' => 'IPA', 'url' => env('GAME_IOS_IPA_URL')],
                ],
                'notes' => [
                    'iOS asks you to trust the developer once it is installed, under Settings, General, VPN & Device Management.',
                ],
            ],
        ],

        /*
         * What a machine needs, as tabs on the page.
         *
         * A starting point rather than a measurement: these are the figures a
         * stock client is usually quoted at, and a server running a modified
         * one should correct them. Remove a group and its tab goes with it.
         */
        'requirements' => [
            [
                'heading' => 'Minimum',
                'rows' => [
                    ['label' => 'Operating system', 'value' => 'Windows 7 or newer, 64-bit'],
                    ['label' => 'Processor', 'value' => 'Dual core, 2 GHz'],
                    ['label' => 'Memory', 'value' => '2 GB RAM'],
                    ['label' => 'Graphics', 'value' => 'DirectX 9.0c compatible'],
                    ['label' => 'Storage', 'value' => '6 GB available'],
                    ['label' => 'Network', 'value' => 'Broadband connection'],
                ],
            ],
            [
                'heading' => 'Recommended',
                'rows' => [
                    ['label' => 'Operating system', 'value' => 'Windows 10 or 11, 64-bit'],
                    ['label' => 'Processor', 'value' => 'Quad core, 3 GHz'],
                    ['label' => 'Memory', 'value' => '8 GB RAM'],
                    ['label' => 'Graphics', 'value' => 'Dedicated card, DirectX 11'],
                    ['label' => 'Storage', 'value' => '10 GB available, solid state'],
                    ['label' => 'Network', 'value' => 'Broadband connection'],
                ],
            ],
            [
                'heading' => 'Android',
                'rows' => [
                    ['label' => 'Operating system', 'value' => 'Android 8.0 or newer'],
                    ['label' => 'Memory', 'value' => '3 GB RAM'],
                    ['label' => 'Storage', 'value' => '6 GB available'],
                    ['label' => 'Network', 'value' => 'Wi-Fi or mobile data'],
                ],
            ],
            [
                'heading' => 'iOS',
                'rows' => [
                    ['label' => 'Operating system', 'value' => 'iOS 14 or newer'],
                    ['label' => 'Device', 'value' => 'iPhone 8 or newer, or any iPad from 2017'],
                    ['label' => 'Storage', 'value' => '6 GB available'],
                    ['label' => 'Network', 'value' => 'Wi-Fi or mobile data'],
                ],
            ],
        ],

        /*
         * What to do once the download finishes.
         *
         * Numbered in the order given. The wording is deliberately not
         * specific to any one package -- a server whose installer differs
         * should say so here rather than leave people guessing at a step that
         * does not match what they downloaded.
         */
        'steps' => [
            [
                'title' => 'Download',
                'description' => 'Pick a mirror above. They carry the same file, so use whichever is fastest for you.',
            ],
            [
                'title' => 'Extract or install',
                'description' => 'Unpack the archive into a folder of its own, or run the installer if the package has one.',
            ],
            [
                'title' => 'Create an account',
                'description' => 'Register on this site. The same account signs you into the game.',
            ],
            [
                'title' => 'Launch',
                'description' => 'Run the launcher and sign in with the account you just made.',
            ],
            [
                'title' => 'Keep it updated',
                'description' => 'The launcher fetches patches on start-up. Let it finish before you log in.',
            ],
        ],
    ],

];
