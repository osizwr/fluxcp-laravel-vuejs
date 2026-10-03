<?php

declare(strict_types=1);

use App\Enums\AccountLevel;

/*
|--------------------------------------------------------------------------
| Log browsers
|--------------------------------------------------------------------------
|
| FluxCP had 22 near-identical modules for reading log tables: cplog/* for the
| panel's own audit trail and logdata/* for rAthena's game logs. Each was its
| own file repeating the same count-then-page-then-render sequence, which is
| why the sort handling and the date filters drifted between them.
|
| They are declarations here, and one controller renders all of them. Adding a
| log view is an entry in this file.
|
| 'connection' is which database the table lives in: 'login' for the panel's
| cp_* tables, 'char_map' for the two rAthena keeps beside the character data,
| and 'logs' for the rest. Operators commonly put the logs database on another
| host, which is why this is not assumed.
|
| 'columns' maps a column to its label and type. Only columns the table
| actually has are selected -- rAthena's log schema varies by version and by
| which log types the operator enabled in log_athena.conf, so a hardcoded
| SELECT breaks on a server that is merely configured differently.
|
| 'filters' are the columns a request may narrow on. 'date' is the column a
| from/to range applies to.
|
| 'level' is the minimum an account needs to open that view. Every one is
| Administrator, which is what FluxCP's access.php had for all 22 of these --
| the levels are not loosened here, because widening who can read the logs is
| an operator's decision and not a migration's.
|
| It is per view rather than one blanket setting so that decision can be made
| view by view. An operator who wants their junior staff to see the item and
| zeny logs for everyday moderation can lower those two without also handing
| over the chat log, which is every private message players have sent each
| other. See docs/MIGRATION_DECISIONS.md (D21).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The panel's own audit trail (cplog/*)
    |--------------------------------------------------------------------------
    */

    'account-bans' => [
        'label' => 'Account bans',
        'legacy' => 'cplog.ban',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_banlog',
        'date' => 'ban_date',
        'columns' => [
            'ban_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'banned_by' => ['label' => 'By', 'type' => 'account'],
            'ban_type' => ['label' => 'Type', 'type' => 'ban_type'],
            'ban_until' => ['label' => 'Until', 'type' => 'datetime'],
            'ban_reason' => ['label' => 'Reason', 'type' => 'text'],
        ],
        'filters' => ['account_id', 'banned_by', 'ban_type'],
    ],

    'ip-bans' => [
        'label' => 'IP bans',
        'legacy' => 'cplog.ipban',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_ipbanlog',
        'date' => 'ban_date',
        'columns' => [
            'ban_date' => ['label' => 'When', 'type' => 'datetime'],
            'ip_address' => ['label' => 'Pattern', 'type' => 'text'],
            'banned_by' => ['label' => 'By', 'type' => 'account'],
            'ban_type' => ['label' => 'Type', 'type' => 'ban_type'],
            'ban_until' => ['label' => 'Until', 'type' => 'datetime'],
            'ban_reason' => ['label' => 'Reason', 'type' => 'text'],
        ],
        'filters' => ['ip_address', 'banned_by'],
    ],

    'panel-logins' => [
        'label' => 'Panel sign-ins',
        'legacy' => 'cplog.login',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_loginlog',
        'date' => 'login_date',
        'columns' => [
            'login_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'username' => ['label' => 'Name tried', 'type' => 'text'],
            'ip' => ['label' => 'Address', 'type' => 'text'],
            'error_code' => ['label' => 'Outcome', 'type' => 'login_outcome'],
        ],
        'filters' => ['account_id', 'username', 'ip', 'error_code'],
    ],

    'registrations' => [
        'label' => 'Registrations',
        'legacy' => 'cplog.create',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_createlog',
        'date' => 'reg_date',
        'columns' => [
            'reg_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'userid' => ['label' => 'Name', 'type' => 'text'],
            'email' => ['label' => 'E-mail', 'type' => 'text'],
            'reg_ip' => ['label' => 'Address', 'type' => 'text'],
            'confirmed' => ['label' => 'Confirmed', 'type' => 'boolean'],
        ],
        'filters' => ['account_id', 'userid', 'email', 'reg_ip'],
    ],

    'password-changes' => [
        'label' => 'Password changes',
        'legacy' => 'cplog.changepass',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_pwchange',
        'date' => 'change_date',
        'columns' => [
            'change_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'change_ip' => ['label' => 'Address', 'type' => 'text'],
        ],
        'filters' => ['account_id', 'change_ip'],
    ],

    'password-resets' => [
        'label' => 'Password resets',
        'legacy' => 'cplog.resetpass',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_resetpass',
        'date' => 'request_date',
        'columns' => [
            'request_date' => ['label' => 'Requested', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'request_ip' => ['label' => 'From', 'type' => 'text'],
            'reset_date' => ['label' => 'Completed', 'type' => 'datetime'],
            'reset_ip' => ['label' => 'Completed from', 'type' => 'text'],
            'reset_done' => ['label' => 'Used', 'type' => 'boolean'],
        ],
        'filters' => ['account_id', 'request_ip'],
        /*
         * `code` is deliberately absent. It is a digest rather than a working
         * token, but a log browser has no reason to show it and listing it
         * would put it in every administrator's browser history.
         */
    ],

    'email-changes' => [
        'label' => 'E-mail changes',
        'legacy' => 'cplog.changemail',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_emailchange',
        'date' => 'request_date',
        'columns' => [
            'request_date' => ['label' => 'Requested', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'old_email' => ['label' => 'From', 'type' => 'text'],
            'new_email' => ['label' => 'To', 'type' => 'text'],
            'request_ip' => ['label' => 'Address', 'type' => 'text'],
            'change_date' => ['label' => 'Confirmed', 'type' => 'datetime'],
            'change_done' => ['label' => 'Done', 'type' => 'boolean'],
        ],
        'filters' => ['account_id', 'old_email', 'new_email'],
    ],

    'transactions' => [
        'label' => 'Transactions',
        'legacy' => 'cplog.paypal',
        'level' => AccountLevel::Administrator,
        'connection' => 'login',
        'table' => 'cp_txnlog',
        'date' => 'process_date',
        'columns' => [
            'process_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'txn_id' => ['label' => 'Transaction', 'type' => 'text'],
            'payer_email' => ['label' => 'Payer', 'type' => 'text'],
            'mc_gross' => ['label' => 'Amount', 'type' => 'number'],
            'mc_currency' => ['label' => 'Currency', 'type' => 'text'],
            'payment_status' => ['label' => 'Status', 'type' => 'text'],
        ],
        'filters' => ['account_id', 'txn_id', 'payer_email', 'payment_status'],
    ],

    /*
    |--------------------------------------------------------------------------
    | rAthena's game logs (logdata/*)
    |--------------------------------------------------------------------------
    |
    | Which of these have any rows depends on `log_athena.conf`. An operator who
    | has not enabled a log type gets an empty table rather than an error.
    |
    */

    'items' => [
        'label' => 'Item movements',
        'legacy' => 'logdata.pick',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'picklog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'type' => ['label' => 'How', 'type' => 'text'],
            'nameid' => ['label' => 'Item', 'type' => 'item'],
            'amount' => ['label' => 'Amount', 'type' => 'number'],
            'refine' => ['label' => 'Refine', 'type' => 'number'],
            'map' => ['label' => 'Map', 'type' => 'text'],
        ],
        'filters' => ['char_id', 'nameid', 'type', 'map'],
    ],

    'zeny' => [
        'label' => 'Zeny movements',
        'legacy' => 'logdata.zeny',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'zenylog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'src_id' => ['label' => 'From', 'type' => 'character'],
            'type' => ['label' => 'How', 'type' => 'text'],
            'amount' => ['label' => 'Amount', 'type' => 'number'],
            'map' => ['label' => 'Map', 'type' => 'text'],
        ],
        'filters' => ['char_id', 'src_id', 'type', 'map'],
    ],

    'mvp' => [
        'label' => 'MVP kills',
        'legacy' => 'logdata.mvp',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'mvplog',
        'date' => 'mvp_date',
        'columns' => [
            'mvp_date' => ['label' => 'When', 'type' => 'datetime'],
            'kill_char_id' => ['label' => 'Killer', 'type' => 'character'],
            'monster_id' => ['label' => 'Monster', 'type' => 'monster'],
            'prize' => ['label' => 'Prize', 'type' => 'item'],
            'mvpexp' => ['label' => 'MVP EXP', 'type' => 'number'],
            'map' => ['label' => 'Map', 'type' => 'text'],
        ],
        'filters' => ['kill_char_id', 'monster_id', 'map'],
    ],

    'commands' => [
        'label' => 'GM commands',
        'legacy' => 'logdata.command',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'atcommandlog',
        'date' => 'atcommand_date',
        'columns' => [
            'atcommand_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'char_name' => ['label' => 'Name', 'type' => 'text'],
            'map' => ['label' => 'Map', 'type' => 'text'],
            'command' => ['label' => 'Command', 'type' => 'text'],
        ],
        'filters' => ['account_id', 'char_name', 'command', 'map'],
    ],

    'chat' => [
        'label' => 'Chat',
        'legacy' => 'logdata.chat',
        /*
         * The most privacy-sensitive table in the database: every private
         * message players have sent each other. Administrator, as the legacy
         * had it, and this is the one view an operator should think hardest
         * about before lowering. See docs/MIGRATION_DECISIONS.md (D21).
         */
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'chatlog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'type' => ['label' => 'Channel', 'type' => 'text'],
            'src_charid' => ['label' => 'From', 'type' => 'character'],
            'src_charname' => ['label' => 'Name', 'type' => 'text'],
            'dst_charname' => ['label' => 'To', 'type' => 'text'],
            'src_map' => ['label' => 'Map', 'type' => 'text'],
            'message' => ['label' => 'Message', 'type' => 'message'],
        ],
        'filters' => ['src_charname', 'dst_charname', 'type', 'src_map'],
    ],

    'branches' => [
        'label' => 'Dead branches',
        'legacy' => 'logdata.branch',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'branchlog',
        'date' => 'branch_date',
        'columns' => [
            'branch_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'char_name' => ['label' => 'Name', 'type' => 'text'],
            'map' => ['label' => 'Map', 'type' => 'text'],
        ],
        'filters' => ['account_id', 'char_name', 'map'],
    ],

    'npc' => [
        'label' => 'NPC messages',
        'legacy' => 'logdata.npc',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'npclog',
        'date' => 'npc_date',
        'columns' => [
            'npc_date' => ['label' => 'When', 'type' => 'datetime'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'char_name' => ['label' => 'Name', 'type' => 'text'],
            'map' => ['label' => 'Map', 'type' => 'text'],
            'mes' => ['label' => 'Message', 'type' => 'message'],
        ],
        'filters' => ['account_id', 'char_name', 'map'],
    ],

    'cash' => [
        'label' => 'Cash points',
        'legacy' => 'logdata.cashpoints',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'cashlog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'type' => ['label' => 'How', 'type' => 'text'],
            'cash_type' => ['label' => 'Kind', 'type' => 'text'],
            'amount' => ['label' => 'Amount', 'type' => 'number'],
            'map' => ['label' => 'Map', 'type' => 'text'],
        ],
        'filters' => ['char_id', 'type', 'map'],
    ],

    'feeding' => [
        'label' => 'Pet and homunculus feeding',
        'legacy' => 'logdata.feeding',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'feedinglog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'char_id' => ['label' => 'Character', 'type' => 'character'],
            'target_id' => ['label' => 'Target', 'type' => 'number'],
            'type' => ['label' => 'Kind', 'type' => 'text'],
            'intimacy' => ['label' => 'Intimacy', 'type' => 'number'],
            'item_id' => ['label' => 'Item', 'type' => 'item'],
            'map' => ['label' => 'Map', 'type' => 'text'],
        ],
        'filters' => ['char_id', 'type', 'map'],
    ],

    'game-logins' => [
        'label' => 'Game sign-ins',
        'legacy' => 'logdata.login',
        'level' => AccountLevel::Administrator,
        'connection' => 'logs',
        'table' => 'loginlog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'ip' => ['label' => 'Address', 'type' => 'text'],
            'user' => ['label' => 'Name tried', 'type' => 'text'],
            'rcode' => ['label' => 'Code', 'type' => 'number'],
            'log' => ['label' => 'Outcome', 'type' => 'text'],
        ],
        'filters' => ['ip', 'user', 'rcode'],
    ],

    'characters' => [
        'label' => 'Character changes',
        'legacy' => 'logdata.char',
        // rAthena keeps this one beside the character data, not in the logs
        // database.
        'connection' => 'char_map',
        'level' => AccountLevel::Administrator,
        'table' => 'charlog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'char_msg' => ['label' => 'What', 'type' => 'text'],
            'account_id' => ['label' => 'Account', 'type' => 'account'],
            'char_num' => ['label' => 'Slot', 'type' => 'number'],
            'name' => ['label' => 'Name', 'type' => 'text'],
        ],
        'filters' => ['account_id', 'name'],
    ],

    'inter' => [
        'label' => 'Inter-server',
        'legacy' => 'logdata.inter',
        'connection' => 'char_map',
        'level' => AccountLevel::Administrator,
        'table' => 'interlog',
        'date' => 'time',
        'columns' => [
            'time' => ['label' => 'When', 'type' => 'datetime'],
            'log' => ['label' => 'Entry', 'type' => 'text'],
        ],
        'filters' => [],
    ],

];
