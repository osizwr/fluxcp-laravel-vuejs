<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| rAthena server groups
|--------------------------------------------------------------------------
|
| A server group is one login server plus the char/map server pairs that
| share it, together with the databases they use. This mirrors the shape of
| FluxCP's config/servers.php: a panel may front several independent groups,
| and each group may run several char/map pairs against one login server.
|
| Connections for every group are registered with Laravel's database manager
| at runtime, because the set of them is not known until this file is read.
| See docs/MIGRATION_DECISIONS.md (D5).
|
| The single group below is driven entirely by environment variables, which
| covers the common case of one server. To front more than one group, add
| further entries to 'groups' with their own credentials.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default group
    |--------------------------------------------------------------------------
    |
    | The group selected when a visitor has not chosen one. Must be a key of
    | the 'groups' array below.
    |
    */

    'default' => env('RATHENA_DEFAULT_GROUP', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Connection defaults
    |--------------------------------------------------------------------------
    |
    | Merged into every generated connection. Individual groups may override
    | any of these. 'strict' is deliberately off: rAthena's schema predates
    | strict mode and uses zero dates and out-of-range defaults that strict
    | MySQL rejects.
    |
    */

    'connection_defaults' => [
        'driver' => 'mysql',
        'charset' => env('RATHENA_DB_CHARSET', 'utf8mb4'),
        'collation' => env('RATHENA_DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => false,
        'engine' => null,
    ],

    'groups' => [

        'main' => [

            /*
             * Shown in the server switcher. Corresponds to FluxCP's
             * ServerName on the group.
             */
            'name' => env('RATHENA_SERVER_NAME', 'rAthena'),

            /*
             * The login server. Exactly one per group; rAthena's account
             * table lives in its database.
             *
             * 'use_md5' and 'case_sensitive' mirror LoginServer.UseMD5 and
             * the inverse of LoginServer.NoCase. They decide how stored
             * credentials are compared -- see docs/MIGRATION_DECISIONS.md
             * (D1) for why the format is dictated by the emulator.
             *
             * 'default_group_id' is the rAthena group_id assigned to newly
             * registered accounts.
             */
            'login' => [
                'address' => env('RATHENA_LOGIN_ADDRESS', '127.0.0.1'),
                'port' => (int) env('RATHENA_LOGIN_PORT', 6900),
                'use_md5' => (bool) env('RATHENA_LOGIN_USE_MD5', false),
                'case_sensitive' => (bool) env('RATHENA_LOGIN_CASE_SENSITIVE', false),
                'default_group_id' => (int) env('RATHENA_LOGIN_DEFAULT_GROUP_ID', 0),
            ],

            /*
             * Databases. 'login' holds the account table and 18 of the
             * panel-owned cp_* tables; 'char_map' holds the character tables
             * and the other 7. They default to the same database because
             * that is how rAthena ships, but may be split.
             *
             * 'logs' is configured separately because operators commonly put
             * it on another host, and 'web' is rAthena's own web database.
             */
            'databases' => [

                'login' => [
                    'host' => env('RATHENA_DB_HOST', '127.0.0.1'),
                    'port' => env('RATHENA_DB_PORT', '3306'),
                    'database' => env('RATHENA_DB_DATABASE', 'ragnarok'),
                    'username' => env('RATHENA_DB_USERNAME', 'ragnarok'),
                    'password' => env('RATHENA_DB_PASSWORD', ''),
                ],

                'char_map' => [
                    'host' => env('RATHENA_CHARMAP_DB_HOST', env('RATHENA_DB_HOST', '127.0.0.1')),
                    'port' => env('RATHENA_CHARMAP_DB_PORT', env('RATHENA_DB_PORT', '3306')),
                    'database' => env('RATHENA_CHARMAP_DB_DATABASE', env('RATHENA_DB_DATABASE', 'ragnarok')),
                    'username' => env('RATHENA_CHARMAP_DB_USERNAME', env('RATHENA_DB_USERNAME', 'ragnarok')),
                    'password' => env('RATHENA_CHARMAP_DB_PASSWORD', env('RATHENA_DB_PASSWORD', '')),
                ],

                'logs' => [
                    'host' => env('RATHENA_LOGS_DB_HOST', env('RATHENA_DB_HOST', '127.0.0.1')),
                    'port' => env('RATHENA_LOGS_DB_PORT', env('RATHENA_DB_PORT', '3306')),
                    'database' => env('RATHENA_LOGS_DB_DATABASE', env('RATHENA_DB_DATABASE', 'ragnarok')),
                    'username' => env('RATHENA_LOGS_DB_USERNAME', env('RATHENA_DB_USERNAME', 'ragnarok')),
                    'password' => env('RATHENA_LOGS_DB_PASSWORD', env('RATHENA_DB_PASSWORD', '')),
                ],

                'web' => [
                    'host' => env('RATHENA_WEB_DB_HOST', env('RATHENA_DB_HOST', '127.0.0.1')),
                    'port' => env('RATHENA_WEB_DB_PORT', env('RATHENA_DB_PORT', '3306')),
                    'database' => env('RATHENA_WEB_DB_DATABASE', env('RATHENA_DB_DATABASE', 'ragnarok')),
                    'username' => env('RATHENA_WEB_DB_USERNAME', env('RATHENA_DB_USERNAME', 'ragnarok')),
                    'password' => env('RATHENA_WEB_DB_PASSWORD', env('RATHENA_DB_PASSWORD', '')),
                ],

            ],

            /*
             * Char/map server pairs sharing the login server above. Each pair
             * is probed independently for status, carries its own rates, and
             * may use its own char/map database.
             */
            'char_map_servers' => [

                'main' => [
                    'name' => env('RATHENA_CHARMAP_NAME', 'rAthena'),
                    'renewal' => (bool) env('RATHENA_RENEWAL', true),
                    'max_character_slots' => (int) env('RATHENA_MAX_CHAR_SLOTS', 9),

                    /*
                     * Timezone this pair's clock runs in. Affects the War of
                     * Emperium schedule below and any time shown for it.
                     * Falls back to app.timezone.
                     */
                    'timezone' => env('RATHENA_CHARMAP_TIMEZONE'),

                    /*
                     * Optional per-pair database overrides, layered on top of
                     * the group's char_map credentials. Mirrors FluxCP's
                     * per-pair 'Database' setting, for the case where one
                     * login server fronts pairs with separate character
                     * databases. Any connection key may be overridden.
                     */
                    'database' => [
                        // 'database' => 'ragnarok_second_world',
                    ],

                    'char_server' => [
                        'address' => env('RATHENA_CHAR_ADDRESS', '127.0.0.1'),
                        'port' => (int) env('RATHENA_CHAR_PORT', 6121),
                    ],

                    'map_server' => [
                        'address' => env('RATHENA_MAP_ADDRESS', '127.0.0.1'),
                        'port' => (int) env('RATHENA_MAP_PORT', 5121),
                    ],

                    /*
                     * Maps a character may not be returned to by the
                     * "reset position" tool. FluxCP defaults to sec_pri
                     * (the jail map).
                     */
                    'reset_deny_maps' => ['sec_pri'],

                    /*
                     * Displayed on the server information page. These are
                     * not read from the emulator -- rAthena keeps them in
                     * conf files the panel cannot see -- so they are
                     * declared here, as FluxCP also requires.
                     */
                    'rates' => [
                        'base_exp' => (int) env('RATHENA_RATE_BASE_EXP', 100),
                        'job_exp' => (int) env('RATHENA_RATE_JOB_EXP', 100),
                        'mvp_exp' => (int) env('RATHENA_RATE_MVP_EXP', 100),
                        'common_drop' => (int) env('RATHENA_RATE_COMMON_DROP', 100),
                        'heal_drop' => (int) env('RATHENA_RATE_HEAL_DROP', 100),
                        'usable_drop' => (int) env('RATHENA_RATE_USABLE_DROP', 100),
                        'equip_drop' => (int) env('RATHENA_RATE_EQUIP_DROP', 100),
                        'card_drop' => (int) env('RATHENA_RATE_CARD_DROP', 100),
                        'mvp_item_drop' => (int) env('RATHENA_RATE_MVP_ITEM_DROP', 100),
                        'drop_rate_cap' => (int) env('RATHENA_RATE_DROP_CAP', 9000),
                    ],

                    /*
                     * War of Emperium windows, as [start_day, start_time,
                     * end_day, end_time] with 0 = Sunday. Times are read in
                     * the pair's timezone.
                     */
                    'woe_schedule' => [
                        // ['day' => 0, 'start' => '12:00', 'end_day' => 0, 'end' => '14:00'],
                    ],

                    /*
                     * Route names refused while WoE is running, unless the
                     * viewer holds the bypass permission. FluxCP defaults to
                     * hiding who-is-online and map statistics so players
                     * cannot scout castles from the website.
                     */
                    'woe_restricted_routes' => [
                        'characters.online',
                        'characters.map-stats',
                    ],
                ],

            ],

        ],

    ],

];
