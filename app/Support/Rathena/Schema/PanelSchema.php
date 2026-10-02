<?php

declare(strict_types=1);

namespace App\Support\Rathena\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;

/**
 * The 25 tables the panel owns inside rAthena's databases.
 *
 * These are not Laravel migrations, and cannot be. A migration runs once
 * against one connection, but these tables must exist in every configured
 * server group's database, and how many of those there are is not known until
 * config/rathena.php has been read. The panel:install-schema command applies
 * this definition per group instead; Laravel migrations still own the tables
 * that belong to the application itself.
 *
 * Placement follows the legacy installer exactly: 18 tables in the login
 * database and 7 in the char/map database. They are not consolidated, because
 * the panel joins them directly against `login` and `char` in the same schema,
 * and relocating them would turn those joins into cross-database joins that
 * break as soon as an operator splits the databases across hosts.
 *
 * The definitions were derived by executing all 44 of FluxCP's versioned
 * schema files in version order against a throwaway database and reading back
 * the resolved result, so they reproduce the real end state rather than an
 * interpretation of the deltas.
 *
 * See docs/MIGRATION_DECISIONS.md (D4) and docs/DATABASE_ANALYSIS.md.
 */
final class PanelSchema
{
    /**
     * Tables belonging in the login database, keyed by table name.
     *
     * @return array<string, Closure(Blueprint): void>
     */
    public static function loginTables(): array
    {
        return [

            /*
             * Account ban history. Append-only: a lift is a new row.
             */
            'cp_banlog' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->unsignedInteger('banned_by')->nullable();
                $table->tinyInteger('ban_type');
                $table->dateTime('ban_until');
                $table->dateTime('ban_date');
                $table->text('ban_reason');
                $table->index(['account_id', 'banned_by'], 'account_id');
            },

            /*
             * News entries.
             */
            'cp_cmsnews' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title', 100);
                $table->text('body');
                $table->string('link', 100);
                $table->string('author', 100);
                $table->dateTime('created');
                $table->dateTime('modified');
            },

            /*
             * Static page entries.
             */
            'cp_cmspages' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('path', 100);
                $table->string('title', 100);
                $table->text('body');
                $table->dateTime('modified');
            },

            /*
             * CMS key/value settings.
             */
            'cp_cmssettings' => static function (Blueprint $table): void {
                $table->string('name', 128);
                $table->string('value', 128);
                $table->unique('name', 'name');
            },

            /*
             * Registration audit and e-mail confirmation codes.
             *
             * The user_pass column exists for compatibility with an existing
             * FluxCP database and is never written. See D2.
             */
            'cp_createlog' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->string('userid', 23);
                $table->string('user_pass', 32);
                $table->enum('sex', ['m', 'f', 's'])->default('M');
                $table->string('email', 39);
                $table->dateTime('reg_date');
                $table->string('reg_ip', 39);
                $table->dateTime('delete_date')->nullable();
                $table->tinyInteger('confirmed')->default(1);
                $table->string('confirm_code', 32)->nullable();
                $table->dateTime('confirm_expire')->nullable();
                $table->index('userid', 'name');
                $table->index('account_id', 'account_id');
            },

            /*
             * Item-shop credit balance per account, plus a last-donation marker.
             */
            'cp_credits' => static function (Blueprint $table): void {
                $table->unsignedInteger('account_id');
                $table->unsignedInteger('balance')->default(0);
                $table->dateTime('last_donation_date')->nullable();
                $table->float('last_donation_amount')->nullable();
                $table->primary('account_id');
            },

            /*
             * E-mail change requests and confirmation codes.
             */
            'cp_emailchange' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('code', 32);
                $table->integer('account_id');
                $table->string('old_email', 39);
                $table->string('new_email', 39);
                $table->dateTime('request_date');
                $table->string('request_ip', 39);
                $table->dateTime('change_date')->nullable();
                $table->string('change_ip', 39)->nullable();
                $table->tinyInteger('change_done')->default(0);
                $table->index('account_id', 'account_id');
            },

            /*
             * IP ban history, paired with rAthena's own ipbanlist.
             */
            'cp_ipbanlog' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('ip_address', 39);
                $table->unsignedInteger('banned_by')->nullable();
                $table->tinyInteger('ban_type');
                $table->dateTime('ban_until');
                $table->dateTime('ban_date');
                $table->text('ban_reason');
                $table->index('ip_address', 'ip_address');
                $table->index('banned_by', 'banned_by');
            },

            /*
             * Control-panel sign-in attempts.
             *
             * The password column exists for compatibility and is never
             * written. See D2.
             */
            'cp_loginlog' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('account_id')->nullable();
                $table->string('username', 23);
                $table->string('password', 32);
                $table->string('ip', 39);
                $table->dateTime('login_date');
                $table->tinyInteger('error_code')->nullable();
                $table->index('account_id', 'account_id');
            },

            /*
             * Open key/value preferences per account.
             */
            'cp_loginprefs' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->string('name', 80);
                $table->string('value', 255)->nullable();
                $table->dateTime('create_date')->nullable();
                $table->index('account_id', 'account_id');
            },

            /*
             * Password change audit.
             *
             * The password columns exist for compatibility and are never
             * written. See D2.
             */
            'cp_pwchange' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('account_id');
                $table->string('old_password', 32);
                $table->string('new_password', 32)->nullable();
                $table->dateTime('change_date');
                $table->string('change_ip', 39);
                $table->index('account_id', 'account_id');
            },

            /*
             * Password reset requests and codes.
             *
             * The password columns exist for compatibility and are never
             * written. See D2.
             */
            'cp_resetpass' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('code', 32);
                $table->integer('account_id');
                $table->string('old_password', 32);
                $table->string('new_password', 32)->nullable();
                $table->dateTime('request_date');
                $table->string('request_ip', 39);
                $table->dateTime('reset_date')->nullable();
                $table->string('reset_ip', 39)->nullable();
                $table->tinyInteger('reset_done')->default(0);
                $table->index('account_id', 'account_id');
            },

            /*
             * Support tickets.
             */
            'cp_servicedesk' => static function (Blueprint $table): void {
                $table->increments('ticket_id');
                $table->integer('account_id');
                $table->integer('category');
                $table->string('status', 12)->default('Pending');
                $table->text('char_id');
                $table->timestamp('timestamp')->useCurrent();
                $table->text('sslink');
                $table->text('chatlink');
                $table->text('videolink');
                $table->string('subject', 64)->default(0);
                $table->text('text');
                $table->string('ip', 39)->default(0);
                $table->integer('team')->default(1);
                $table->text('curemail');
                $table->string('lastreply', 24)->default(0);
            },

            /*
             * Support ticket replies.
             */
            'cp_servicedeska' => static function (Blueprint $table): void {
                $table->increments('action_id');
                $table->integer('ticket_id');
                $table->string('author', 32);
                $table->text('text');
                $table->text('action');
                $table->timestamp('timestamp')->useCurrent();
                $table->string('ip', 39)->default(0);
                $table->integer('isstaff')->default(0);
            },

            /*
             * Support ticket categories.
             */
            'cp_servicedeskcat' => static function (Blueprint $table): void {
                $table->increments('cat_id');
                $table->string('name', 32);
                $table->integer('display')->default(1);
            },

            /*
             * Support desk configuration.
             */
            'cp_servicedesksettings' => static function (Blueprint $table): void {
                $table->integer('account_id');
                $table->string('account_name', 32);
                $table->string('prefered_name', 32);
                $table->integer('team');
                $table->integer('emailalerts')->default(0);
                $table->timestamp('timestamp')->useCurrent();
                $table->primary('account_id');
            },

            /*
             * Donors trusted to bypass the donation hold.
             */
            'cp_trusted' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->string('email', 255);
                $table->dateTime('create_date');
                $table->dateTime('delete_date')->nullable();
                $table->index('account_id', 'account_id');
            },

            /*
             * Raw PayPal IPN payloads, one row per notification.
             */
            'cp_txnlog' => static function (Blueprint $table): void {
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
                $table->string('first_name', 30)->nullable();
                $table->string('last_name', 40)->nullable();
                $table->string('address_street', 50)->nullable();
                $table->string('address_city', 30)->nullable();
                $table->string('address_state', 30)->nullable();
                $table->string('address_zip', 20)->nullable();
                $table->string('address_country', 30)->nullable();
                $table->string('address_status', 11)->nullable();
                $table->string('payer_email', 60)->nullable();
                $table->string('payer_status', 10)->nullable();
                $table->string('payment_type', 10)->nullable();
                $table->string('notify_version', 10)->nullable();
                $table->string('verify_sign', 255)->nullable();
                $table->string('referrer_id', 13)->nullable();
                $table->dateTime('process_date')->nullable();
                $table->dateTime('hold_until')->nullable();
                $table->index('account_id', 'account_id');
                $table->index('parent_txn_id', 'parent_txn_id');
                $table->index('txn_id', 'txn_id');
            },

        ];
    }

    /**
     * Tables belonging in the char/map database, keyed by table name.
     *
     * @return array<string, Closure(Blueprint): void>
     */
    public static function charMapTables(): array
    {
        return [

            /*
             * Open key/value preferences per character.
             */
            'cp_charprefs' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('account_id');
                $table->unsignedInteger('char_id');
                $table->string('name', 80);
                $table->string('value', 255)->nullable();
                $table->dateTime('create_date')->nullable();
                $table->index(['account_id', 'char_id'], 'account_id');
                $table->index('char_id', 'char_id');
            },

            /*
             * Remote at-commands queued for the map server to consume.
             */
            'cp_commands' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('command', 128)->default(0);
                $table->string('issuer', 32)->default(0);
                $table->integer('account_id')->default(0);
                $table->integer('done')->default(0);
                $table->timestamp('timestamp')->useCurrent();
            },

            /*
             * Admin-authored item description overrides.
             */
            'cp_itemdesc' => static function (Blueprint $table): void {
                $table->increments('itemid');
                $table->text('itemdesc');
            },

            /*
             * The item-shop catalogue.
             */
            'cp_itemshop' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('nameid')->default(0);
                $table->integer('category')->nullable();
                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedInteger('cost');
                $table->text('info')->nullable();
                $table->tinyInteger('use_existing')->default(0);
                $table->dateTime('create_date');
                $table->index('nameid', 'nameid');
            },

            /*
             * Daily peak online-player counts.
             */
            'cp_onlinepeak' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('users')->default(0);
                $table->date('date');
            },

            /*
             * Item-shop purchases awaiting or completed in-game delivery.
             */
            'cp_redeemlog' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('nameid')->default(0);
                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedInteger('cost');
                $table->unsignedInteger('account_id');
                $table->unsignedInteger('char_id')->nullable();
                $table->unsignedTinyInteger('redeemed');
                $table->dateTime('redemption_date')->nullable();
                $table->dateTime('purchase_date');
                $table->integer('credits_before');
                $table->integer('credits_after');
                $table->index(['nameid', 'account_id', 'char_id'], 'nameid');
            },

            /*
             * Credit transfers between accounts.
             */
            'cp_xferlog' => static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('from_account_id');
                $table->unsignedInteger('target_account_id');
                $table->unsignedInteger('target_char_id');
                $table->unsignedInteger('amount');
                $table->unsignedTinyInteger('for_free')->default(0);
                $table->dateTime('transfer_date');
                $table->index(['from_account_id', 'target_account_id', 'target_char_id'], 'from_account_id');
            },

        ];
    }

    /**
     * Every panel-owned table name, for reporting and verification.
     *
     * @return list<string>
     */
    public static function allTableNames(): array
    {
        return [
            ...array_keys(self::loginTables()),
            ...array_keys(self::charMapTables()),
        ];
    }
}
