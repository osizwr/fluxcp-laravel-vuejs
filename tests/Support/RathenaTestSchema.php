<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->unsignedInteger('banned_by');
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
}
