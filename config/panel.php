<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Panel policy
|--------------------------------------------------------------------------
|
| Operator policy ported from FluxCP's config/application.php. Only settings
| the application actually reads are listed; the legacy file's 361 options
| also covered framework concerns that now belong to Laravel's own config,
| and presentational copy that belongs in translations.
|
| See docs/MIGRATION_DECISIONS.md (D12) for how the legacy options were split,
| including which of these are intended to become admin-editable settings
| stored in the database rather than deployed as a file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Mirrors the validation Flux_LoginServer::register() performed, with the
    | legacy defaults preserved.
    |
    | 'username_pattern' is the legacy UsernameAllowedChars setting, which was
    | interpolated into a PCRE character class. It is kept as an explicit
    | pattern rather than a fragment so that it cannot be turned into a
    | malformed or overly permissive expression by a stray character.
    |
    */

    'registration' => [
        'enabled' => (bool) env('PANEL_REGISTRATION_ENABLED', true),

        'username' => [
            'min_length' => (int) env('PANEL_USERNAME_MIN', 4),
            // rAthena's login.userid is varchar(23); longer is truncated.
            'max_length' => (int) env('PANEL_USERNAME_MAX', 23),
            'pattern' => '/^[a-zA-Z0-9_]+$/',
        ],

        'password' => [
            'min_length' => (int) env('PANEL_PASSWORD_MIN', 8),
            /*
             * The legacy default was 31, one below the width of rAthena's
             * varchar(32) column. With cleartext storage anything longer is
             * silently truncated and could then never be matched, so the
             * credential verifier caps it independently of this setting.
             */
            'max_length' => (int) env('PANEL_PASSWORD_MAX', 31),
            'min_uppercase' => (int) env('PANEL_PASSWORD_MIN_UPPER', 1),
            'min_lowercase' => (int) env('PANEL_PASSWORD_MIN_LOWER', 1),
            'min_numbers' => (int) env('PANEL_PASSWORD_MIN_NUMBER', 1),
            'min_symbols' => (int) env('PANEL_PASSWORD_MIN_SYMBOL', 0),
            'allow_username_inside' => (bool) env('PANEL_PASSWORD_ALLOW_USERNAME', false),

            /*
             * A stricter policy for staff accounts, applied at or above the
             * panel level named below. Any key omitted here falls back to the
             * player value above.
             *
             * The legacy panel had this the wrong way round. changepass.php
             * read `$account->group_level < Flux::config('EnableGMPassSecurity')`
             * to decide whether to apply the GM rules, which applied the
             * stricter policy to ordinary players and the looser one to game
             * masters -- the opposite of the intent, and of what the setting's
             * name says. See docs/MIGRATION_DECISIONS.md (D17).
             */
            'staff' => [
                'applies_at_or_above_level' => env('PANEL_STAFF_PASSWORD_LEVEL', 1) === null
                    ? null
                    : (int) env('PANEL_STAFF_PASSWORD_LEVEL', 1),

                'min_length' => (int) env('PANEL_STAFF_PASSWORD_MIN', 12),
                'min_uppercase' => (int) env('PANEL_STAFF_PASSWORD_MIN_UPPER', 1),
                'min_lowercase' => (int) env('PANEL_STAFF_PASSWORD_MIN_LOWER', 1),
                'min_numbers' => (int) env('PANEL_STAFF_PASSWORD_MIN_NUMBER', 1),
                'min_symbols' => (int) env('PANEL_STAFF_PASSWORD_MIN_SYMBOL', 1),
            ],
        ],

        'allow_duplicate_emails' => (bool) env('PANEL_ALLOW_DUPLICATE_EMAILS', false),
        'require_email_confirmation' => (bool) env('PANEL_REQUIRE_EMAIL_CONFIRMATION', false),
        'email_confirmation_expires_after_hours' => (int) env('PANEL_EMAIL_CONFIRM_EXPIRE_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    |
    | The three allow_* switches are FluxCP's AllowIpBanLogin,
    | AllowTempBanLogin and AllowPermBanLogin. They exist so an operator can
    | let a banned player reach the panel -- to read the ban reason or open a
    | support ticket -- without lifting the ban in game. They default to off,
    | as in the legacy panel.
    |
    */

    'login' => [
        'allow_ip_banned' => (bool) env('PANEL_ALLOW_IP_BANNED_LOGIN', false),
        'allow_temporarily_banned' => (bool) env('PANEL_ALLOW_TEMP_BANNED_LOGIN', false),
        'allow_permanently_banned' => (bool) env('PANEL_ALLOW_PERM_BANNED_LOGIN', false),

        /*
         * Attempts allowed per username+IP pair before throttling, and the
         * lockout in seconds. The legacy panel had no rate limiting at all,
         * which with cleartext credentials made online guessing cheap.
         */
        'max_attempts' => (int) env('PANEL_LOGIN_MAX_ATTEMPTS', 5),

        /*
         * Looser, because an address is shared by everyone behind one NAT.
         * This limit is a backstop against one host sweeping many accounts,
         * not the per-account protection.
         */
        'max_attempts_per_address' => (int) env('PANEL_LOGIN_MAX_ATTEMPTS_PER_ADDRESS', 30),

        'decay_seconds' => (int) env('PANEL_LOGIN_DECAY_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA
    |--------------------------------------------------------------------------
    */

    'captcha' => [
        'on_registration' => (bool) env('PANEL_CAPTCHA_ON_REGISTRATION', false),
        'on_login' => (bool) env('PANEL_CAPTCHA_ON_LOGIN', false),

        /*
         * 'native' renders a challenge the panel generates itself;
         * 'recaptcha' delegates to Google. Mirrors the legacy UseCaptcha plus
         * EnableReCaptcha pair, which encoded the same choice as two booleans.
         */
        'driver' => env('PANEL_CAPTCHA_DRIVER', 'native'),

        'recaptcha' => [
            'site_key' => env('RECAPTCHA_SITE_KEY'),
            'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        ],

        /*
         * The challenge the 'native' driver generates itself.
         *
         * The character set leaves out 0/O/1/I/L and anything else that is
         * ambiguous in a distorted image, because a challenge a person cannot
         * read is a challenge that stops registrations rather than robots.
         */
        'native' => [
            'length' => (int) env('PANEL_CAPTCHA_LENGTH', 5),
            'characters' => env('PANEL_CAPTCHA_CHARACTERS', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'),
            'width' => (int) env('PANEL_CAPTCHA_WIDTH', 200),
            'height' => (int) env('PANEL_CAPTCHA_HEIGHT', 70),

            /*
             * How long a generated challenge stays answerable. Short, because
             * the answer sits in the session and a long window lets one solved
             * challenge be replayed across many submissions.
             */
            'expires_after_seconds' => (int) env('PANEL_CAPTCHA_EXPIRE_SECONDS', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset
    |--------------------------------------------------------------------------
    |
    | The legacy flow had three problems this one does not. It e-mailed a
    | newly generated password in cleartext, it stored both the old and the new
    | password in cp_resetpass, and it never checked how old a reset code was
    | -- cp_resetpass.request_date was written and then never read, so a code
    | from a mailbox compromised years later still worked.
    |
    | See docs/MIGRATION_DECISIONS.md (D15, D16).
    |
    */

    'password_reset' => [
        'enabled' => (bool) env('PANEL_PASSWORD_RESET_ENABLED', true),

        'expires_after_hours' => (int) env('PANEL_PASSWORD_RESET_EXPIRE_HOURS', 2),

        /*
         * Accounts at or above this panel level cannot have their password
         * reset by e-mail, because holding the mailbox would then be enough to
         * take over a game master account. The legacy NoResetPassGroupLevel
         * setting; 1 is the junior game master tier. Null allows every account.
         */
        'blocked_at_or_above_level' => env('PANEL_PASSWORD_RESET_BLOCK_LEVEL', 1) === null
            ? null
            : (int) env('PANEL_PASSWORD_RESET_BLOCK_LEVEL', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | E-mail change
    |--------------------------------------------------------------------------
    |
    | The legacy RequireChangeConfirm setting. With confirmation on, the new
    | address has to be proven reachable before it replaces the old one, so a
    | hijacked session cannot redirect the account's recovery mail to an
    | address the attacker merely typed in.
    |
    */

    'email_change' => [
        'require_confirmation' => (bool) env('PANEL_REQUIRE_EMAIL_CHANGE_CONFIRMATION', true),

        /*
         * The legacy flow never expired these either: confirmemail.php looked
         * up cp_emailchange by code and change_done only.
         */
        'expires_after_hours' => (int) env('PANEL_EMAIL_CHANGE_EXPIRE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound mail
    |--------------------------------------------------------------------------
    |
    | Whether the account credential e-mails go through the queue.
    |
    | Off by default, which is the opposite of the usual advice and deliberate:
    | a queued message on a server with no `queue:work` running is a message
    | that is never sent, and the people affected are the ones who cannot
    | finish registering or get back into their account -- so they cannot
    | report it either. Turn this on once a worker is running and registration
    | stops waiting on your mail server.
    |
    */

    'mail' => [
        'queue' => (bool) env('PANEL_QUEUE_MAIL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Characters
    |--------------------------------------------------------------------------
    |
    | What a player may do to their own characters from the panel. FluxCP's
    | DivorceKeepChild and DivorceKeepRings.
    |
    | Every one of these actions refuses while the character is online, which
    | is not configurable: rAthena holds the character in memory and writes it
    | back on logout, so a change made meanwhile is silently reverted.
    |
    */

    'characters' => [
        'divorce_keeps_child' => (bool) env('PANEL_DIVORCE_KEEPS_CHILD', false),
        'divorce_keeps_rings' => (bool) env('PANEL_DIVORCE_KEEPS_RINGS', false),

        // How many maps the map statistics page lists.
        'map_statistics_limit' => (int) env('PANEL_MAP_STATS_LIMIT', 50),

        /*
         * Leave staff off the map counts. FluxCP's HideFromMapStats. Without
         * it a game master sitting on a map nobody else is on is located by a
         * count of one, which is the same leak the per-character "hide my map"
         * preference exists to prevent. Null counts everybody; 1 is the junior
         * game master tier.
         */
        'hide_maps_at_or_above_level' => env('PANEL_MAP_STATS_HIDE_AT_LEVEL', 1) === null
            ? null
            : (int) env('PANEL_MAP_STATS_HIDE_AT_LEVEL', 1),

        /*
         * Credits charged for a gender change. 0 is free. FluxCP's
         * ChargeGenderChange.
         */
        'gender_change_cost' => (int) env('PANEL_GENDER_CHANGE_COST', 0),

        /*
         * Credit transfers between players. FluxCP allowed them with no cap,
         * which makes the panel a laundering route for credits bought on one
         * account and moved to another. A cap is a cheap brake; 0 disables
         * transfers entirely.
         */
        'credit_transfer' => [
            'enabled' => (bool) env('PANEL_CREDIT_TRANSFER_ENABLED', true),
            'max_per_transfer' => (int) env('PANEL_CREDIT_TRANSFER_MAX', 0),
        ],

        /*
         * Web commands queued for the game server to run. Off by default:
         * the table is read by a script with game-master powers, so what goes
         * into it matters more than most tables here.
         */
        'web_commands' => [
            'enabled' => (bool) env('PANEL_WEB_COMMANDS_ENABLED', false),

            /*
             * Commands the panel will queue, as shell globs: `@refresh` is one
             * command, `@storage*` a family. An empty list accepts nothing, so
             * turning the feature on without configuring it is inert rather
             * than open.
             *
             * FluxCP inserted whatever was submitted, with no allow-list at
             * all, into a table an rAthena script runs with game-master
             * powers. See docs/MIGRATION_DECISIONS.md (D23).
             */
            'allowed' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PANEL_WEB_COMMANDS_ALLOWED', '')),
            ))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guilds
    |--------------------------------------------------------------------------
    */

    'guilds' => [
        /*
         * Serve guild emblems. Needs the GD extension; turn it off rather
         * than have every emblem request fail on a server without it.
         */
        'emblems' => (bool) env('PANEL_GUILD_EMBLEMS', true),

        /*
         * How long a decoded emblem is cached. Decoding means inflating and
         * re-encoding an image per request, and emblems change rarely.
         * FluxCP's EmblemCacheInterval, in seconds rather than minutes. 0
         * disables caching.
         */
        'emblem_cache_seconds' => (int) env('PANEL_EMBLEM_CACHE_SECONDS', 600),

        // Cap on a member-list export, so one request cannot stream a very
        // large guild's roster repeatedly.
        'export_limit' => (int) env('PANEL_GUILD_EXPORT_LIMIT', 500),

        /*
         * Show guild storage contents only to the guild master, rather than to
         * every member. FluxCP's GStorageLeaderOnly. Staff with ViewGuild see
         * it either way.
         */
        'storage_leader_only' => (bool) env('PANEL_GUILD_STORAGE_LEADER_ONLY', false),

        /*
         * Cap on how many storage rows one guild page returns. A guild store
         * holds hundreds of stacks and each one costs a name lookup.
         */
        'storage_limit' => (int) env('PANEL_GUILD_STORAGE_LIMIT', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | IP bans
    |--------------------------------------------------------------------------
    |
    | Patterns that may never be banned. A ban covering the operator's own
    | range locks every administrator out of the panel *and* the game server,
    | and the only way back is editing the database by hand — so this list is
    | the one safety net worth having. Add your game server, web server and
    | your own address.
    |
    | Entries are shell-style globs in the same `203.0.113.*` form the ban list
    | itself uses, and a whitelisted single address is also protected from any
    | pattern that would cover it.
    |
    | FluxCP's IpWhitelistPattern was a PCRE interpolated into a regular
    | expression, with its own config file warning "This string isn't escaped
    | so be careful which chars you use!". An operator writing the obvious
    | `192.168.*.*` got a pattern where `.` matched any character and `*` was a
    | quantifier — whitelisting far more than intended, or failing to compile.
    |
    | The defaults are the legacy ones: localhost, and the 0.x range, which
    | covers the all-interfaces wildcards.
    |
    */

    'ip_bans' => [
        'whitelist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PANEL_IP_BAN_WHITELIST', '127.0.0.1,0.*.*.*,0.0.0.0')),
        ))),

        // Default length of a new ban, in days, when none is given.
        'default_days' => (int) env('PANEL_IP_BAN_DEFAULT_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Item shop
    |--------------------------------------------------------------------------
    |
    | Items bought with credits and collected in game. FluxCP's ItemShopMaxCost,
    | ItemShopMaxQuantity, ItemShopItemPerPage and ShopImageExtensions.
    |
    | The panel never writes to a character's inventory: a purchase writes a
    | `cp_redeemlog` row and an rAthena script hands the item over. The
    | character may be online, and the map server would overwrite anything
    | written underneath it.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Items
    |--------------------------------------------------------------------------
    */

    'items' => [
        /*
         * Show the description imported from the client's itemInfo.lua on an
         * item's page. FluxCP's ShowItemDesc. Off when nothing has been
         * imported costs a query per item page, so it is a switch rather than
         * a guess.
         */
        'show_descriptions' => (bool) env('PANEL_SHOW_ITEM_DESCRIPTIONS', true),

        /*
         * Cap on an uploaded itemInfo.lua, in kilobytes. A full file for a
         * current client is around 4 MB.
         */
        'item_info_max_kilobytes' => (int) env('PANEL_ITEM_INFO_MAX_KB', 16_384),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discord notifications
    |--------------------------------------------------------------------------
    |
    | Operator notifications to a Discord channel, ported from FluxCP's
    | lib/functions/discordwebhook.php.
    |
    */

    'discord' => [
        'enabled' => (bool) env('PANEL_DISCORD_ENABLED', false),

        /*
         * https only, because the URL carries a token that authorises posting
         * to the channel. Keep it in the environment, not in this file.
         */
        'webhook_url' => env('PANEL_DISCORD_WEBHOOK_URL', ''),

        'timeout_seconds' => (int) env('PANEL_DISCORD_TIMEOUT', 5),

        /*
         * Off by default, as with the mail queue and for the same reason: a
         * queued job on a server with no `queue:work` running is a job that
         * never happens. Turn it on once a worker is running and the
         * notification leaves the request entirely.
         */
        'queue' => (bool) env('PANEL_QUEUE_DISCORD', false),

        'events' => [
            'registration' => (bool) env('PANEL_DISCORD_ON_REGISTER', true),
            'ticket' => (bool) env('PANEL_DISCORD_ON_TICKET', true),
            'web_command' => (bool) env('PANEL_DISCORD_ON_WEB_COMMAND', true),
            'broadcast' => (bool) env('PANEL_DISCORD_ON_BROADCAST', true),

            /*
             * Off by default, unlike the legacy, which sent the exception
             * message to the channel. An exception message is where a database
             * credential or a file path ends up, and a Discord channel is read
             * by more people than a log file is. Turn it on knowing that.
             */
            'exception' => (bool) env('PANEL_DISCORD_ON_EXCEPTION', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | News
    |--------------------------------------------------------------------------
    */

    'news' => [
        /*
         * Where news comes from. FluxCP's CMSNewsType, as a word rather than
         * as 1 or 2.
         *
         *   'panel' -- the news table, edited here.
         *   'feed'  -- an external RSS or Atom feed, so a server whose
         *              announcements live on its forum does not write them
         *              twice.
         */
        'source' => env('PANEL_NEWS_SOURCE', 'panel'),

        // FluxCP's CMSNewsRSS. Only http and https are accepted.
        'feed_url' => env('PANEL_NEWS_FEED_URL', ''),

        // FluxCP's CMSNewsLimit, for the feed. Panel news is paginated.
        'feed_limit' => (int) env('PANEL_NEWS_FEED_LIMIT', 4),

        /*
         * The legacy read the feed on every request with no cache and no
         * timeout, so a forum that stopped answering took the front page with
         * it.
         */
        'feed_cache_seconds' => (int) env('PANEL_NEWS_FEED_CACHE_SECONDS', 900),
        'feed_timeout_seconds' => (int) env('PANEL_NEWS_FEED_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support desk
    |--------------------------------------------------------------------------
    */

    'service_desk' => [
        /*
         * Let staff award credits when replying to a ticket, for a player who
         * reported a bug or an abuse. FluxCP's SDEnableCreditRewards and
         * SDCreditReward.
         *
         * The maximum is this port's own. The legacy read the amount straight
         * from the form with no ceiling, so a mistyped figure awarded a
         * fortune and the only trace was a line of free text.
         */
        'credit_rewards' => [
            'enabled' => (bool) env('PANEL_SD_CREDIT_REWARDS', true),
            'default' => (int) env('PANEL_SD_CREDIT_REWARD', 5),
            'maximum' => (int) env('PANEL_SD_CREDIT_REWARD_MAX', 500),
        ],
    ],

    'item_shop' => [
        'enabled' => (bool) env('PANEL_ITEM_SHOP_ENABLED', true),

        // Caps on what an operator may list, not on what a player may spend.
        'max_cost' => (int) env('PANEL_ITEM_SHOP_MAX_COST', 99999),
        'max_quantity' => (int) env('PANEL_ITEM_SHOP_MAX_QUANTITY', 99),

        /*
         * Item images live on a disk rather than in the database, as the
         * legacy had them, named after the shop item's id.
         */
        'image_disk' => env('PANEL_ITEM_SHOP_IMAGE_DISK', 'public'),
        'image_directory' => env('PANEL_ITEM_SHOP_IMAGE_DIR', 'shop'),
        'image_extensions' => ['png', 'jpg', 'jpeg', 'gif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Donations
    |--------------------------------------------------------------------------
    |
    | Turning payments into shop credits. FluxCP's AcceptDonations,
    | CreditExchangeRate, MinDonationAmount, DonationCurrency, PayPalIpnUrl,
    | PayPalBusinessEmail and PayPalReceiverEmails.
    |
    | Off by default. Crediting an account cannot be undone in practice -- the
    | player spends the credits and the items are delivered in game -- so this
    | is something an operator turns on deliberately after setting the
    | addresses below, not something that is live because the panel was
    | installed.
    |
    | NOTE on IPN: this implements PayPal's Instant Payment Notification
    | because that is what FluxCP used and what existing rAthena servers have
    | configured. PayPal has since moved to webhooks and IPN is maintained
    | rather than recommended. Check whether your account still supports it.
    |
    */

    'donations' => [
        'enabled' => (bool) env('PANEL_DONATIONS_ENABLED', false),

        /*
         * The verification endpoint. HTTPS, and not configurable down to
         * plain HTTP: this request is the only thing standing between a forged
         * POST and free credits, and FluxCP made it over port 80.
         */
        'verify_url' => env('PANEL_DONATIONS_VERIFY_URL', 'https://ipnpb.paypal.com/cgi-bin/webscr'),

        // Where the donate form sends people.
        'payment_url' => env('PANEL_DONATIONS_PAYMENT_URL', 'https://www.paypal.com/cgi-bin/webscr'),

        'business_email' => env('PANEL_DONATIONS_BUSINESS_EMAIL'),

        /*
         * Addresses this server owns. A notification naming anything else is
         * refused -- otherwise a payment made to somebody else credits a
         * player here. Comma separated.
         */
        'receiver_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PANEL_DONATIONS_RECEIVER_EMAILS', '')),
        ))),

        'currency' => env('PANEL_DONATIONS_CURRENCY', 'USD'),

        // Credits per unit of currency.
        'credits_per_unit' => (float) env('PANEL_DONATIONS_CREDITS_PER_UNIT', 1.0),

        'minimum_amount' => (float) env('PANEL_DONATIONS_MINIMUM', 2.0),

        /*
         * How long a payment from an unseen address is held before its credits
         * become spendable, in hours. 0 credits immediately.
         *
         * The hold is what makes a chargeback survivable: if the payment is
         * reversed inside the window the credits are cancelled and nothing was
         * ever spent. Without it, a reversal leaves the server having
         * delivered items for money it no longer has.
         *
         * Once a payer's first donation clears, their address is trusted and
         * later ones are immediate. `panel:release-held-credits` runs hourly
         * and does both halves.
         */
        'hold_hours' => (int) env('PANEL_DONATIONS_HOLD_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account maintenance
    |--------------------------------------------------------------------------
    |
    | The legacy panel performed both of these inline, on every single request,
    | from main/preprocess. They are scheduled tasks here instead.
    |
    */

    'maintenance' => [
        'prune_unconfirmed_accounts' => (bool) env('PANEL_PRUNE_UNCONFIRMED', false),
        'release_held_credits' => (bool) env('PANEL_RELEASE_HELD_CREDITS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server status probing
    |--------------------------------------------------------------------------
    |
    | The legacy panel opened a TCP socket to each server on every page view,
    | so a firewalled port made the whole site hang for ServerStatusTimeout
    | seconds per server. Results are cached here, and the probe is what feeds
    | the realtime status broadcast.
    |
    */

    'server_status' => [
        'timeout_seconds' => (float) env('PANEL_SERVER_STATUS_TIMEOUT', 2),
        'cache_seconds' => (int) env('PANEL_SERVER_STATUS_CACHE_SECONDS', 30),

        /*
         * Show the recorded peak concurrent player count alongside the live
         * one. The legacy EnablePeakDisplay setting; off by default, because
         * cp_onlinepeak is only populated if something is recording into it.
         */
        'show_peak' => (bool) env('PANEL_SERVER_STATUS_SHOW_PEAK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Rankings
    |--------------------------------------------------------------------------
    |
    | Ported from the legacy HidePermBannedCharRank, HideTempBannedCharRank,
    | RankingHideGroupLevel and CharRankingThreshold settings.
    |
    */

    'rankings' => [
        'limit' => (int) env('PANEL_RANKING_LIMIT', 100),

        'hide_permanently_banned' => (bool) env('PANEL_RANKING_HIDE_PERM_BANNED', true),
        'hide_temporarily_banned' => (bool) env('PANEL_RANKING_HIDE_TEMP_BANNED', false),

        /*
         * Hide characters belonging to accounts at or above this panel level,
         * so staff with developer-granted levels or zeny do not head a player
         * ladder. Null disables the filter. 1 is the junior game master tier.
         */
        'hide_at_or_above_level' => env('PANEL_RANKING_HIDE_AT_LEVEL', 1) === null
            ? null
            : (int) env('PANEL_RANKING_HIDE_AT_LEVEL', 1),

        /*
         * Exclude characters whose account has not signed in for this many
         * days. 0 disables the filter.
         */
        'inactive_after_days' => (int) env('PANEL_RANKING_INACTIVE_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    |
    | Aggregate counts over the game tables. These are full-table counts and
    | the blocks that show them sit on the front page, so without caching the
    | landing page becomes the most expensive query in the application.
    |
    */

    'statistics' => [
        'cache_seconds' => (int) env('PANEL_STATISTICS_CACHE_SECONDS', 300),

        /*
         * Leave staff zeny out of the total. FluxCP's InfoHideZenyGroupLevel.
         * The figure is watched for inflation, and a game master who granted
         * themselves two billion for a test moves it more than the economy
         * does. Null counts everybody; 1 is the junior game master tier.
         */
        'hide_zeny_at_or_above_level' => env('PANEL_STATS_HIDE_ZENY_AT_LEVEL', 1) === null
            ? null
            : (int) env('PANEL_STATS_HIDE_ZENY_AT_LEVEL', 1),

        /*
         * Sort the class distribution by how many characters hold each job
         * rather than by job id. FluxCP's SortJobsByAmount, defaulting the
         * other way: a chart is read for which job is popular.
         */
        'sort_classes_by_count' => (bool) env('PANEL_STATS_SORT_CLASSES_BY_COUNT', true),
    ],

    /*
     * Which CMS page holds the terms of service. The legacy rendered a
     * template edited on disk; here it is an ordinary page, edited where every
     * other page is.
     */
    'terms_of_service_path' => env('PANEL_TERMS_PATH', 'terms'),

    'pagination' => [
        'per_page' => (int) env('PANEL_PER_PAGE', 20),
        'max_per_page' => (int) env('PANEL_MAX_PER_PAGE', 100),
    ],

];
