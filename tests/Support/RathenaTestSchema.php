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

        /* Mirrors PanelSchema: the typed confirmation code, hashed. */
        $schema->create('cp_registration_otp', function (Blueprint $table): void {
            $table->unsignedInteger('account_id')->primary();
            $table->string('code', 32);
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
        });

        /*
         * The panel's IP ban history, paired with rAthena's own `ipbanlist`.
         * Append-only: a lift is a new row, not a deletion.
         */
        $schema->create('cp_ipbanlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('ip_address', 39);
            $table->unsignedInteger('banned_by')->nullable();
            $table->tinyInteger('ban_type');
            $table->dateTime('ban_until');
            $table->dateTime('ban_date');
            $table->text('ban_reason')->nullable();
            $table->index('ip_address');
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

        /*
         * The full production shape, not a subset. The donation flow writes
         * most of these, and `hold_until` is what makes a chargeback
         * survivable.
         */
        $schema->create('cp_txnlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id')->nullable()->default(0);
            $table->string('server_name', 255)->nullable();
            $table->integer('credits')->nullable()->default(0);
            $table->string('receiver_email', 60)->nullable();
            $table->string('item_name', 100)->nullable();
            $table->string('item_number', 10)->nullable();
            $table->string('quantity', 6)->nullable();
            $table->string('payment_status', 20)->nullable();
            $table->string('pending_reason', 20)->nullable();
            $table->string('payment_date', 40)->nullable();
            $table->string('mc_gross', 20)->nullable();
            $table->string('mc_fee', 20)->nullable();
            $table->string('tax', 20)->nullable();
            $table->string('mc_currency', 3)->nullable();
            $table->string('parent_txn_id', 20)->nullable();
            $table->string('txn_id', 20)->nullable();
            $table->string('txn_type', 20)->nullable();
            $table->string('payer_email', 60)->nullable();
            $table->dateTime('process_date')->nullable();
            $table->dateTime('hold_until')->nullable();
            $table->index('account_id');
            $table->index('txn_id');
        });

        $schema->create('cp_xferlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('from_account_id')->default(0);
            $table->unsignedInteger('target_account_id')->default(0);
            $table->unsignedInteger('target_char_id')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedTinyInteger('for_free')->default(0);
            $table->dateTime('transfer_date')->nullable();
            $table->index('from_account_id');
        });

        $schema->create('cp_trusted', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id')->default(0);
            $table->string('email', 255)->default('');
            $table->dateTime('create_date')->nullable();
            $table->dateTime('delete_date')->nullable();
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

        $schema->create('cp_itemshop', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('nameid')->default(0);
            $table->integer('category')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('cost')->default(0);
            $table->text('info')->nullable();
            $table->tinyInteger('use_existing')->default(0);
            $table->dateTime('create_date')->nullable();
            $table->index('nameid');
        });

        $schema->create('cp_redeemlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('nameid')->default(0);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('cost')->default(0);
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->nullable();
            $table->unsignedTinyInteger('redeemed')->default(0);
            $table->dateTime('redemption_date')->nullable();
            $table->dateTime('purchase_date')->nullable();
            $table->integer('credits_before')->default(0);
            $table->integer('credits_after')->default(0);
            $table->index('account_id');
        });

        $schema->create('cp_servicedesk', function (Blueprint $table): void {
            $table->increments('ticket_id');
            $table->integer('account_id')->default(0);
            $table->integer('category')->default(0);
            $table->string('status', 12)->default('Pending');
            $table->text('char_id');
            $table->timestamp('timestamp')->useCurrent();
            $table->text('sslink')->nullable();
            $table->text('chatlink')->nullable();
            $table->text('videolink')->nullable();
            $table->string('subject', 64)->default('');
            $table->text('text');
            $table->string('ip', 39)->default('');
            $table->integer('team')->default(1);
            $table->text('curemail')->nullable();
            $table->string('lastreply', 24)->default('0');
            $table->index('account_id');
        });

        $schema->create('cp_servicedeska', function (Blueprint $table): void {
            $table->increments('action_id');
            $table->integer('ticket_id')->default(0);
            $table->string('author', 32)->default('');
            $table->text('text');
            $table->text('action')->nullable();
            $table->timestamp('timestamp')->useCurrent();
            $table->string('ip', 39)->default('');
            $table->integer('isstaff')->default(0);
            $table->index('ticket_id');
        });

        $schema->create('cp_servicedeskcat', function (Blueprint $table): void {
            $table->increments('cat_id');
            $table->string('name', 32)->default('');
            $table->integer('display')->default(1);
        });

        $schema->create('cp_servicedesksettings', function (Blueprint $table): void {
            $table->integer('account_id')->primary();
            $table->string('account_name', 32)->default('');
            $table->string('prefered_name', 32)->default('');
            $table->integer('team')->default(1);
            $table->integer('emailalerts')->default(0);
            $table->timestamp('timestamp')->useCurrent();
        });

        $schema->create('cp_cmspages', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('path', 100);
            $table->string('title', 100);
            $table->text('body');
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
            /*
             * The character view orders on `attribute` -- a broken item sorts
             * before an intact one -- and reports the rest, so a test schema
             * without them exercised the ordering against columns that do not
             * exist here but do exist on a real server.
             */
            $table->unsignedTinyInteger('attribute')->default(0);
            $table->unsignedTinyInteger('bound')->default(0);
            $table->unsignedInteger('expire_time')->default(0);
            /*
             * Renewal random options. A pre-renewal server has no such
             * columns, which is why they are read only when the server group
             * is configured as renewal.
             */
            $table->unsignedSmallInteger('option_id0')->default(0);
            $table->smallInteger('option_val0')->default(0);
            $table->unsignedSmallInteger('option_id1')->default(0);
            $table->smallInteger('option_val1')->default(0);
            $table->unsignedSmallInteger('option_id2')->default(0);
            $table->smallInteger('option_val2')->default(0);
            $table->unsignedSmallInteger('option_id3')->default(0);
            $table->smallInteger('option_val3')->default(0);
            $table->unsignedSmallInteger('option_id4')->default(0);
            $table->smallInteger('option_val4')->default(0);
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
            /*
             * The stat block a character's page shows. The ladder only needs
             * the level and intimacy, which is why an earlier revision of
             * this schema stopped there.
             */
            $table->unsignedSmallInteger('str')->default(0);
            $table->unsignedSmallInteger('agi')->default(0);
            $table->unsignedSmallInteger('vit')->default(0);
            $table->unsignedSmallInteger('int')->default(0);
            $table->unsignedSmallInteger('dex')->default(0);
            $table->unsignedSmallInteger('luk')->default(0);
            $table->unsignedInteger('hp')->default(0);
            $table->unsignedInteger('max_hp')->default(0);
            $table->unsignedInteger('sp')->default(0);
            $table->unsignedInteger('max_sp')->default(0);
            $table->unsignedSmallInteger('skill_point')->default(0);
            // A released homunculus stays in the table, so the ladder filters
            // on this rather than assuming every row is live.
            $table->boolean('alive')->default(true);
            $table->index('char_id');
        });

        /*
         * A character's pet. The mob it is a pet of is named through the
         * monster merge, so a server's own mob_db2 entries name their pets
         * correctly.
         */
        $schema->create('pet', function (Blueprint $table): void {
            $table->increments('pet_id');
            $table->unsignedSmallInteger('class')->default(0);
            $table->string('name', 24)->default('');
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedSmallInteger('level')->default(1);
            $table->unsignedInteger('egg_id')->default(0);
            $table->unsignedInteger('equip')->default(0);
            $table->smallInteger('intimate')->default(0);
            $table->smallInteger('hungry')->default(0);
            $table->boolean('rename_flag')->default(false);
            $table->boolean('incubate')->default(false);
        });

        /*
         * Parties. Only the name and the leader are read, but the leader is
         * read as a character id and joined for a name.
         */
        $schema->create('party', function (Blueprint $table): void {
            $table->increments('party_id');
            $table->string('name', 24)->default('');
            $table->boolean('exp')->default(false);
            $table->boolean('item')->default(false);
            $table->unsignedInteger('leader_id')->default(0);
            $table->unsignedInteger('leader_char')->default(0);
        });

        /*
         * Player shops. rAthena names these inconsistently, and a vending
         * stall sells out of the seller's cart while a buying store holds no
         * stock -- which is why only the vending items join through
         * cart_inventory.
         */
        $schema->create('vendings', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedTinyInteger('sex')->default(1);
            $table->string('map', 20)->default('');
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->string('title', 80)->default('');
            $table->boolean('autotrade')->default(false);
        });

        $schema->create('vending_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('vending_id')->default(0);
            $table->unsignedSmallInteger('index')->default(0);
            $table->unsignedInteger('cartinventory_id')->default(0);
            $table->unsignedSmallInteger('amount')->default(0);
            $table->unsignedInteger('price')->default(0);
            $table->index('vending_id');
        });

        $schema->create('buyingstores', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedTinyInteger('sex')->default(1);
            $table->string('map', 20)->default('');
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->string('title', 80)->default('');
            $table->unsignedInteger('limit')->default(0);
            $table->boolean('autotrade')->default(false);
        });

        $schema->create('buyingstore_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('buyingstore_id')->default(0);
            $table->unsignedSmallInteger('index')->default(0);
            $table->unsignedInteger('nameid')->default(0);
            $table->unsignedSmallInteger('amount')->default(0);
            $table->unsignedInteger('price')->default(0);
            $table->index('buyingstore_id');
        });

        $schema->create('cart_inventory', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedInteger('nameid')->default(0);
            $table->smallInteger('amount')->default(0);
            $table->boolean('identify')->default(false);
            $table->tinyInteger('refine')->default(0);
            $table->unsignedInteger('card0')->default(0);
            $table->unsignedInteger('card1')->default(0);
            $table->integer('card2')->default(0);
            $table->integer('card3')->default(0);
            $table->unsignedTinyInteger('attribute')->default(0);
            $table->unsignedTinyInteger('bound')->default(0);
            $table->unsignedInteger('expire_time')->default(0);
            $table->index('char_id');
        });

        /*
         * rAthena keeps friendship one-directional: a row says that `char_id`
         * added `friend_id`, and the reverse is a separate row that may not
         * exist.
         */
        $schema->create('friends', function (Blueprint $table): void {
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedInteger('friend_account')->default(0);
            $table->unsignedInteger('friend_id')->default(0);
            $table->primary(['char_id', 'friend_id']);
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

        /*
         * A guild's rank titles. `position` on guild_member is an index into
         * this table for the same guild, so the join needs both columns --
         * joining on the index alone matches every guild's rank of that
         * number.
         */
        $schema->create('guild_position', function (Blueprint $table): void {
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('name', 24)->default('');
            $table->unsignedTinyInteger('mode')->default(0);
            $table->unsignedTinyInteger('exp_mode')->default(0);

            $table->primary(['guild_id', 'position']);
        });

        /*
         * Expulsion history. rAthena keeps the expelled member's name rather
         * than only their id, because the character may since have been
         * deleted.
         */
        $schema->create('guild_expulsion', function (Blueprint $table): void {
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedInteger('account_id')->default(0);
            $table->string('name', 24)->default('');
            $table->string('mes', 40)->default('');

            $table->primary(['guild_id', 'name']);
        });

        $schema->create('guild_storage', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('guild_id')->default(0);
            $table->unsignedInteger('nameid')->default(0);
            $table->smallInteger('amount')->default(0);
            $table->boolean('identify')->default(false);
            $table->tinyInteger('refine')->default(0);
            $table->unsignedInteger('card0')->default(0);
            $table->unsignedInteger('card1')->default(0);
            $table->integer('card2')->default(0);
            $table->integer('card3')->default(0);
            $table->unsignedTinyInteger('attribute')->default(0);
            $table->unsignedTinyInteger('bound')->default(0);
            $table->unsignedInteger('expire_time')->default(0);

            $table->index('guild_id');
        });

        $schema->create('cp_onlinepeak', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users')->default(0);
            $table->date('date');
        });

        /*
         * rAthena keeps these two beside the character data rather than in the
         * logs database.
         */
        /*
         * rAthena's script variable store. Some servers keep the siege
         * schedule here so a script can change it without a restart.
         */
        /*
         * Commands queued for the game server to run, and the credit transfer
         * log. rAthena keeps both beside the character data.
         */
        $schema->create('cp_commands', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('command', 128)->default('');
            $table->string('issuer', 32)->default('');
            $table->integer('account_id')->default(0);
            $table->integer('done')->default(0);
            $table->timestamp('timestamp')->useCurrent();
        });

        $schema->create('mapreg', function (Blueprint $table): void {
            $table->string('varname', 32)->default('');
            $table->unsignedInteger('index')->default(0);
            $table->string('value', 255)->default('');
            $table->primary(['varname', 'index']);
        });

        $schema->create('charlog', function (Blueprint $table): void {
            $table->dateTime('time')->useCurrent();
            $table->string('char_msg', 255)->default('char select');
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedTinyInteger('char_num')->default(0);
            $table->string('name', 30)->default('');
        });

        $schema->create('interlog', function (Blueprint $table): void {
            $table->dateTime('time')->useCurrent();
            $table->string('log', 255)->default('');
        });

        /*
         * Item descriptions imported from the client's itemInfo.lua. `itemid`
         * is the primary key, as FluxCP declared it, which is what lets an
         * import replace a description rather than duplicate it.
         */
        $schema->create('cp_itemdesc', function (Blueprint $table): void {
            $table->increments('itemid');
            $table->text('itemdesc');
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
            // Declared by the items view and present on a real server, so the
            // schema carries it rather than leaving the column untested.
            $table->tinyInteger('refine')->default(0);
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

        /*
         * rAthena's game logs. Which of these a real server populates depends
         * on `log_athena.conf`; the browser intersects declared columns with
         * the real schema so a differently configured server is an empty table
         * rather than a 500.
         */
        $schema->create('atcommandlog', function (Blueprint $table): void {
            $table->increments('atcommand_id');
            $table->dateTime('atcommand_date')->useCurrent();
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->string('char_name', 25)->default('');
            $table->string('map', 11)->default('');
            $table->text('command');
        });

        $schema->create('chatlog', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('time')->useCurrent();
            $table->string('type', 1)->default('O');
            $table->unsignedInteger('type_id')->default(0);
            $table->unsignedInteger('src_charid')->default(0);
            $table->string('src_accountid', 11)->default('');
            $table->string('src_map', 11)->default('');
            $table->string('src_charname', 25)->default('');
            $table->string('dst_charname', 25)->default('');
            $table->string('message', 150)->default('');
        });

        $schema->create('branchlog', function (Blueprint $table): void {
            $table->increments('branch_id');
            $table->dateTime('branch_date')->useCurrent();
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->string('char_name', 25)->default('');
            $table->string('map', 11)->default('');
        });

        $schema->create('npclog', function (Blueprint $table): void {
            $table->increments('npc_id');
            $table->dateTime('npc_date')->useCurrent();
            $table->unsignedInteger('account_id')->default(0);
            $table->unsignedInteger('char_id')->default(0);
            $table->string('char_name', 25)->default('');
            $table->string('map', 11)->default('');
            $table->string('mes', 255)->default('');
        });

        $schema->create('cashlog', function (Blueprint $table): void {
            $table->string('id', 20)->primary();
            $table->dateTime('time')->useCurrent();
            $table->unsignedInteger('char_id')->default(0);
            $table->string('type', 1)->default('S');
            $table->string('cash_type', 1)->default('O');
            $table->integer('amount')->default(0);
            $table->string('map', 11)->default('');
        });

        $schema->create('feedinglog', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('time')->useCurrent();
            $table->unsignedInteger('char_id')->default(0);
            $table->unsignedInteger('target_id')->default(0);
            $table->string('type', 1)->default('P');
            $table->smallInteger('intimacy')->default(0);
            $table->unsignedInteger('item_id')->default(0);
            $table->string('map', 11)->default('');
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
                /*
                 * Strings, as real renewal rAthena stores them: the SQL is
                 * generated from the YAML and holds `Formless`, `Medium`,
                 * `Water`. An earlier revision of this schema used the
                 * pre-renewal numeric layout here, which hid a bug -- the
                 * resource cast these to int, and `(int) 'Formless'` is 0, so
                 * every monster on a renewal server reported race 0.
                 *
                 * The pre-renewal numeric layout is covered by mob_db below.
                 */
                $table->string('Size', 20)->nullable();
                $table->string('Race', 20)->nullable();
                $table->string('Element', 20)->nullable();
                $table->unsignedTinyInteger('ElementLevel')->nullable();

                foreach ([
                    'mode_aggressive', 'mode_assist', 'mode_canattack', 'mode_canmove',
                    'mode_looter', 'mode_mvp', 'mode_detector', 'mode_norandomwalk',
                ] as $mode) {
                    $table->unsignedTinyInteger($mode)->nullable();
                }
            });
        }

        /*
         * The pre-renewal monster tables, with the numeric race, size and
         * element layout. Both layouts exist on real servers and the panel
         * reads either, so both are here -- a schema that models only one
         * cannot catch a cast that is wrong on the other.
         *
         * `Element` packs the element level into the same column as
         * `element + level * 20`, which is why it is wider than ten.
         */
        foreach (['mob_db', 'mob_db2'] as $table) {
            $schema->create($table, function (Blueprint $table): void {
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
