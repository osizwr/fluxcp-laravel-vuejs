<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Builds the rAthena tables the test suite needs.
 *
 * This is a test fixture, not an installer. The panel must never create or
 * migrate rAthena's own tables on a real server -- that is the emulator's
 * job -- so this lives in the test namespace and is only ever pointed at a
 * disposable database.
 *
 * The column definitions are written here rather than loaded from rAthena's
 * sql-files for two reasons: the suite stays self-contained and does not need
 * a checkout of the emulator, and this project does not redistribute
 * rAthena's GPL-licensed files. Each table carries the columns the panel
 * actually reads, which is a subset of the real schema -- enough to exercise
 * the queries, deliberately not a reproduction of the emulator's schema.
 */
final class RathenaTestSchema
{
    /**
     * Databases this fixture is permitted to drop tables in.
     *
     * Laravel's own dropAllTables() is correctly scoped to the connection's
     * schema, so this is defence in depth rather than a fix. It is here
     * because this class destroys structure, and a destructive operation
     * pointed at the wrong database should fail loudly rather than succeed.
     *
     * @var list<string>
     */
    private const DESTROYABLE_DATABASES = [
        'fluxcp_test',
        'fluxcp_test_logs',
    ];

    /**
     * Tables whose AUTO_INCREMENT must be restored after a truncate.
     *
     * rAthena does not start these at 1: accounts begin at 2,000,000 and
     * characters at 150,000. TRUNCATE resets the counter regardless of the
     * declared starting value, so without this the fixture reproduces the real
     * id range only until the first test clears it -- and a test asserting
     * anything about id ranges would then be asserting the wrong thing.
     *
     * @var array<string, int>
     */
    public const STARTING_IDS = [
        'login' => 2000000,
        'char' => 150000,
    ];

    /**
     * Create every table across the three connections.
     *
     * The connections may point at the same database -- that is rAthena's
     * default, and the login and char/map roles share one schema unless an
     * operator splits them -- so each distinct database is emptied exactly
     * once before anything is created. Dropping per role would otherwise wipe
     * tables that a previous role had just built.
     */
    public static function create(string $loginConnection, string $charMapConnection, string $logsConnection): void
    {
        self::truncateDatabases([$loginConnection, $charMapConnection, $logsConnection]);

        self::createLoginTables($loginConnection);
        self::createCharMapTables($charMapConnection);
        self::createLogTables($logsConnection);
    }

    /**
     * Drop every table in each distinct database behind these connections.
     *
     * @param  list<string>  $connections
     */
    private static function truncateDatabases(array $connections): void
    {
        $seen = [];

        foreach ($connections as $connection) {
            $database = Schema::connection($connection)->getConnection()->getDatabaseName();

            if (! in_array($database, self::DESTROYABLE_DATABASES, strict: true)) {
                throw new RuntimeException(
                    "Refusing to drop tables in the database '{$database}' (connection "
                    ."'{$connection}'). Only ".implode(', ', self::DESTROYABLE_DATABASES)
                    .' may be used as a test fixture. Check the RATHENA_* values in phpunit.xml.'
                );
            }

            if (in_array($database, $seen, strict: true)) {
                continue;
            }

            $seen[] = $database;
            Schema::connection($connection)->dropAllTables();
        }
    }

    public static function createLoginTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        /*
         * rAthena's account table. account_id is not auto-incrementing from
         * 1: the emulator starts it at 2000000, and the panel must not assume
         * otherwise.
         */
        $schema->create('login', function (Blueprint $table): void {
            $table->increments('account_id')->startingValue(2000000);
            $table->string('userid', 23)->default('');
            // Deliberately narrow: this is the constraint behind D1.
            $table->string('user_pass', 32)->default('');
            $table->enum('sex', ['M', 'F', 'S'])->default('M');
            $table->string('email', 39)->default('');
            $table->tinyInteger('group_id')->default(0);
            $table->unsignedInteger('state')->default(0);
            $table->unsignedInteger('unban_time')->default(0);
            $table->unsignedInteger('expiration_time')->default(0);
            $table->unsignedMediumInteger('logincount')->default(0);
            $table->dateTime('lastlogin')->nullable();
            $table->string('last_ip', 100)->default('');
            $table->date('birthdate')->nullable();
            $table->unsignedTinyInteger('character_slots')->default(0);
            $table->string('pincode', 4)->default('');
            $table->unsignedInteger('pincode_change')->default(0);
            $table->unsignedInteger('vip_time')->default(0);
            $table->tinyInteger('old_group')->default(0);
            $table->string('web_auth_token', 17)->nullable();
            $table->tinyInteger('web_auth_token_enabled')->default(0);

            $table->index('userid', 'name');
        });

        /*
         * rAthena's IP ban list. `list` holds a dotted quad with trailing
         * octets replaced by a literal asterisk, and `rtime` is the expiry.
         */
        $schema->create('ipbanlist', function (Blueprint $table): void {
            $table->string('list', 39)->default('');
            $table->dateTime('btime')->nullable();
            $table->dateTime('rtime')->nullable();
            $table->string('reason', 255)->default('');

            $table->primary(['list', 'btime']);
        });

        self::createLoginOwnedPanelTables($connection);
    }

    /**
     * The panel-owned tables the legacy installer places in the login
     * database, where they can be joined against `login`.
     */
    private static function createLoginOwnedPanelTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('cp_credits', function (Blueprint $table): void {
            $table->unsignedInteger('account_id')->primary();
            $table->integer('balance')->default(0);
            $table->dateTime('last_donation_date')->nullable();
            $table->float('last_donation_amount')->nullable();
        });

        $schema->create('cp_createlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('userid', 23);
            /*
             * Present because an existing FluxCP database has it, but never
             * written by this port. See D2.
             */
            $table->string('user_pass', 32)->default('');
            $table->enum('sex', ['M', 'F', 'S'])->default('M');
            $table->string('email', 39);
            $table->dateTime('reg_date');
            $table->string('reg_ip', 39);
            $table->dateTime('delete_date')->nullable();
            $table->tinyInteger('confirmed')->default(1);
            $table->string('confirm_code', 32)->nullable();
            $table->dateTime('confirm_expire')->nullable();

            $table->index('account_id');
        });

        $schema->create('cp_banlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            /*
             * Nullable, matching PanelSchema: null means the panel itself
             * acted rather than a member of staff, as when a registration is
             * held pending e-mail confirmation.
             */
            $table->unsignedInteger('banned_by')->nullable();
            $table->tinyInteger('ban_type');
            $table->dateTime('ban_until')->nullable();
            $table->dateTime('ban_date');
            $table->text('ban_reason')->nullable();

            $table->index('account_id');
        });

        $schema->create('cp_loginlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('username', 23);
            // Retained for legacy compatibility, never written. See D2.
            $table->string('password', 32)->default('');
            $table->string('ip', 39);
            $table->integer('error_code')->nullable();
            $table->dateTime('login_date');

            $table->index('account_id');
        });

        $schema->create('cp_pwchange', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('account_id');
            // Retained for compatibility, never written. See D2.
            $table->string('old_password', 32)->default('');
            $table->string('new_password', 32)->nullable();
            $table->dateTime('change_date');
            $table->string('change_ip', 39)->default('');

            $table->index('account_id');
        });

        $schema->create('cp_resetpass', function (Blueprint $table): void {
            $table->increments('id');
            // The stored value is a digest of the token, never the token. See D15.
            $table->string('code', 32);
            $table->integer('account_id');
            // Retained for compatibility, never written. See D2.
            $table->string('old_password', 32)->default('');
            $table->string('new_password', 32)->nullable();
            $table->dateTime('request_date');
            $table->string('request_ip', 39)->default('');
            $table->dateTime('reset_date')->nullable();
            $table->string('reset_ip', 39)->nullable();
            $table->tinyInteger('reset_done')->default(0);

            $table->index('account_id');
        });

        $schema->create('cp_emailchange', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 32);
            $table->integer('account_id');
            $table->string('old_email', 39);
            $table->string('new_email', 39);
            $table->dateTime('request_date');
            $table->string('request_ip', 39)->default('');
            $table->dateTime('change_date')->nullable();
            $table->string('change_ip', 39)->nullable();
            $table->tinyInteger('change_done')->default(0);

            $table->index('account_id');
        });

        $schema->create('cp_cmsnews', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title', 100);
            $table->text('body');
            $table->string('link', 100)->default('');
            $table->string('author', 100)->default('');
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
        });

        $schema->create('cp_loginprefs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('name', 255);
            $table->string('value', 255)->nullable();
            $table->dateTime('create_date')->nullable();

            $table->index(['account_id', 'name']);
        });
    }

    public static function createCharMapTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        self::createReferenceTables($connection);

        /*
         * rAthena keeps the death count in char_reg_num under the key
         * PC_DIE_COUNTER rather than as a column on `char`, which is why the
         * death ladder joins rather than selects. `key` is reserved in MySQL
         * and stays quoted.
         */
        /*
         * A subset of rAthena's inventory table. Resetting a look unequips
         * everything, and a divorce removes the wedding rings, so both need
         * the card columns the rings are identified by.
         */
        $schema->create('inventory', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedInteger('nameid')->default(0);
            $table->smallInteger('amount')->default(0);
            $table->unsignedInteger('equip')->default(0);
            $table->boolean('identify')->default(false);
            $table->tinyInteger('refine')->default(0);
            $table->unsignedInteger('card0')->default(0);
            $table->unsignedInteger('card1')->default(0);
            $table->integer('card2')->default(0);
            $table->integer('card3')->default(0);
            $table->boolean('favorite')->default(false);
            $table->index('char_id');
        });

        $schema->create('char_reg_num', function (Blueprint $table): void {
            $table->unsignedInteger('char_id')->default(0);
            $table->string('key', 32)->default('');
            $table->unsignedInteger('index')->default(0);
            $table->bigInteger('value')->default(0);
            $table->primary(['char_id', 'key', 'index']);
        });

        $schema->create('homunculus', function (Blueprint $table): void {
            $table->increments('homun_id');
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedSmallInteger('class')->default(0);
            $table->string('name', 24)->default('');
            $table->unsignedSmallInteger('level')->default(0);
            $table->unsignedInteger('exp')->default(0);
            $table->unsignedInteger('intimacy')->default(0);
            $table->unsignedSmallInteger('hunger')->default(0);
            // A released homunculus stays in the table, so the ladder filters
            // on this rather than assuming every row is live.
            $table->boolean('alive')->default(true);
            $table->index('char_id');
        });

        $schema->create('guild_alliance', function (Blueprint $table): void {
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedInteger('alliance_id')->default(0);
            // 0 is an ally, 1 an enemy.
            $table->tinyInteger('opposition')->default(0);
            $table->string('name', 24)->default('');
            $table->primary(['guild_id', 'alliance_id']);
        });

        $schema->create('guild_castle', function (Blueprint $table): void {
            $table->unsignedInteger('castle_id')->primary();
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedInteger('economy')->default(0);
            $table->unsignedInteger('defense')->default(0);
            $table->index('guild_id');
        });

        /*
         * A subset of rAthena's 80-column `char` table: the columns the panel
         * reads. `char` is a reserved word and must stay quoted.
         */
        $schema->create('char', function (Blueprint $table): void {
            $table->increments('char_id')->startingValue(150000);
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedTinyInteger('char_num')->default(0);
            $table->string('name', 30)->default('');
            $table->unsignedSmallInteger('class')->default(0);
            $table->unsignedSmallInteger('base_level')->default(1);
            $table->unsignedSmallInteger('job_level')->default(1);
            $table->unsignedBigInteger('base_exp')->default(0);
            $table->unsignedBigInteger('job_exp')->default(0);
            $table->integer('zeny')->default(0);
            $table->unsignedInteger('party_id')->default(0);
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedInteger('pet_id')->default(0);
            $table->unsignedInteger('homun_id')->default(0);
            $table->unsignedInteger('clan_id')->default(0);
            $table->unsignedInteger('partner_id')->default(0);
            $table->unsignedInteger('father')->default(0);
            $table->unsignedInteger('mother')->default(0);
            $table->unsignedInteger('child')->default(0);
            $table->integer('fame')->default(0);
            $table->string('last_map', 11)->default('');
            $table->unsignedSmallInteger('last_x')->default(0);
            $table->unsignedSmallInteger('last_y')->default(0);
            $table->string('save_map', 11)->default('');
            $table->unsignedSmallInteger('save_x')->default(0);
            $table->unsignedSmallInteger('save_y')->default(0);
            $table->tinyInteger('online')->default(0);
            // Unix time at which a pending deletion becomes final; 0 = live.
            $table->unsignedInteger('delete_date')->default(0);
            $table->unsignedInteger('unban_time')->default(0);
            $table->enum('sex', ['M', 'F', 'U'])->default('U');
            $table->dateTime('last_login')->nullable();
            $table->unsignedSmallInteger('hair')->default(0);
            $table->unsignedSmallInteger('hair_color')->default(0);
            $table->unsignedSmallInteger('clothes_color')->default(0);
            // The rest of the appearance, which resetting a look clears.
            $table->unsignedSmallInteger('weapon')->default(0);
            $table->unsignedSmallInteger('shield')->default(0);
            $table->unsignedSmallInteger('head_top')->default(0);
            $table->unsignedSmallInteger('head_mid')->default(0);
            $table->unsignedSmallInteger('head_bottom')->default(0);
            $table->unsignedSmallInteger('body')->default(0);

            $table->unique('name');
            $table->index('account_id');
            $table->index('guild_id');
            $table->index('online');
        });

        $schema->create('guild', function (Blueprint $table): void {
            $table->increments('guild_id');
            $table->string('name', 24)->default('');
            $table->unsignedInteger('char_id')->default(0);
            $table->string('master', 24)->default('');
            $table->unsignedTinyInteger('guild_lv')->default(0);
            $table->unsignedTinyInteger('connect_member')->default(0);
            $table->unsignedTinyInteger('max_member')->default(0);
            $table->unsignedSmallInteger('average_lv')->default(1);
            $table->unsignedBigInteger('exp')->default(0);
            $table->unsignedBigInteger('next_exp')->default(0);
            $table->unsignedTinyInteger('skill_point')->default(0);
            $table->string('mes1', 60)->default('');
            $table->string('mes2', 120)->default('');
            $table->smallInteger('emblem_len')->default(0);
            $table->unsignedInteger('emblem_id')->default(0);
            $table->binary('emblem_data')->nullable();
            $table->dateTime('last_master_change')->nullable();

            $table->index('name');
        });

        $schema->create('guild_member', function (Blueprint $table): void {
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedInteger('exp')->default(0);
            $table->unsignedTinyInteger('position')->default(0);

            $table->primary(['guild_id', 'char_id']);
        });

        $schema->create('cp_onlinepeak', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users')->default(0);
            $table->date('date');
        });

        $schema->create('cp_charprefs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('char_id');
            $table->string('name', 255);
            $table->string('value', 255)->nullable();
            $table->dateTime('create_date')->nullable();

            $table->index(['char_id', 'name']);
        });
    }

    public static function createLogTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('picklog', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('time');
            $table->unsignedInteger('char_id')->default(0);
            $table->string('type', 1)->default('P');
            $table->unsignedInteger('nameid')->default(0);
            $table->integer('amount')->default(1);
            $table->string('map', 11)->default('');

            $table->index('type');
        });

        /*
         * rAthena's own sign-in log. Keyed on the account *name* rather than
         * its id, which is why the history query matches on `user`.
         */
        $schema->create('loginlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('time')->useCurrent();
            $table->string('ip', 39)->default('');
            $table->string('user', 23)->default('');
            $table->tinyInteger('rcode')->default(0);
            $table->string('log', 255)->default('');
        });

        $schema->create('mvplog', function (Blueprint $table): void {
            $table->increments('mvp_id');
            $table->dateTime('mvp_date')->useCurrent();
            $table->unsignedInteger('kill_char_id')->default(0);
            $table->unsignedSmallInteger('monster_id')->default(0);
            $table->unsignedInteger('prize')->default(0);
            $table->unsignedBigInteger('mvpexp')->default(0);
            $table->string('map', 11)->default('');
            $table->index('kill_char_id');
        });

        $schema->create('zenylog', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('time');
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedInteger('src_id')->default(0);
            $table->string('type', 1)->default('S');
            $table->integer('amount')->default(0);
            $table->string('map', 11)->default('');
        });
    }

    /**
     * rAthena's item and monster reference tables, base and override.
     *
     * A representative subset of the real columns rather than all of them:
     * `item_db_re` has around forty and the panel reads a dozen.
     *
     * The two use different primary key spellings on purpose. rAthena has
     * shipped both `id` and `ID` for these across versions, and the merge
     * detects the key rather than assuming it -- a wrong guess joins nothing
     * and silently returns every row twice.
     */
    private static function createReferenceTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        /*
         * Modern rAthena stores each equip location, job, class, trade
         * restriction and flag as its own boolean column rather than as a
         * bitmask, which is why there are so many of them. A representative
         * selection is enough to exercise the decoding.
         */
        foreach (['item_db_re', 'item_db2_re'] as $table) {
            $schema->create($table, function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('name_aegis', 50)->default('');
                $table->string('name_english', 50)->default('');
                $table->string('type', 20)->nullable();
                $table->string('subtype', 20)->nullable();
                $table->unsignedInteger('price_buy')->nullable();
                $table->unsignedInteger('price_sell')->nullable();
                $table->unsignedInteger('weight')->nullable();
                $table->unsignedSmallInteger('attack')->nullable();
                $table->unsignedSmallInteger('defense')->nullable();
                $table->unsignedTinyInteger('range')->nullable();
                $table->unsignedTinyInteger('slots')->nullable();
                $table->unsignedTinyInteger('weapon_level')->nullable();
                $table->unsignedSmallInteger('equip_level_min')->nullable();
                $table->unsignedSmallInteger('equip_level_max')->nullable();
                $table->string('gender', 6)->nullable();
                $table->unsignedSmallInteger('view')->nullable();
                $table->text('script')->nullable();

                foreach ([
                    'location_head_top', 'location_head_mid', 'location_head_low',
                    'location_armor', 'location_right_hand', 'location_left_hand',
                    'location_garment', 'location_shoes',
                    'location_right_accessory', 'location_left_accessory',
                    'job_all', 'job_novice', 'job_swordman', 'job_mage', 'job_archer',
                    'job_acolyte', 'job_merchant', 'job_thief',
                    'class_all', 'class_normal', 'class_upper', 'class_baby',
                    'trade_nodrop', 'trade_notrade', 'trade_nosell', 'trade_nostorage',
                    'flag_buyingstore', 'flag_container', 'flag_bindonequip',
                ] as $attribute) {
                    $table->unsignedTinyInteger($attribute)->nullable();
                }
            });
        }

        foreach (['mob_db_re', 'mob_db2_re'] as $table) {
            $schema->create($table, function (Blueprint $table): void {
                // Capitalised on purpose; see the method comment.
                $table->unsignedInteger('ID')->primary();
                $table->string('Sprite', 50)->default('');
                $table->string('kName', 50)->default('');
                $table->string('iName', 50)->default('');
                $table->unsignedSmallInteger('LV')->default(1);
                $table->unsignedInteger('HP')->default(1);
                $table->unsignedInteger('SP')->default(0);
                $table->unsignedInteger('EXP')->default(0);
                $table->unsignedInteger('JEXP')->default(0);
                $table->unsignedSmallInteger('ATK1')->default(0);
                $table->unsignedSmallInteger('ATK2')->default(0);
                $table->unsignedSmallInteger('DEF')->default(0);
                $table->unsignedSmallInteger('MDEF')->default(0);
                $table->unsignedTinyInteger('MEXP')->default(0);
                $table->unsignedSmallInteger('Size')->default(1);
                $table->unsignedSmallInteger('Race')->default(0);
                $table->unsignedSmallInteger('Element')->default(0);

                foreach ([
                    'mode_aggressive', 'mode_assist', 'mode_canattack', 'mode_canmove',
                    'mode_looter', 'mode_mvp', 'mode_detector', 'mode_norandomwalk',
                ] as $mode) {
                    $table->unsignedTinyInteger($mode)->nullable();
                }
            });
        }
    }
}
