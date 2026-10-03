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

        /*
         * Minimum age in years, enforced against the submitted birthdate.
         * rAthena stores the birthdate and uses it for its own age-gated
         * features, so it is required rather than optional.
         */
        'minimum_age' => (int) env('PANEL_MINIMUM_AGE', 13),
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
    ],

    'pagination' => [
        'per_page' => (int) env('PANEL_PER_PAGE', 20),
        'max_per_page' => (int) env('PANEL_MAX_PER_PAGE', 100),
    ],

];
