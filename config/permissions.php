<?php

declare(strict_types=1);

use App\Enums\AccountLevel;

/*
|--------------------------------------------------------------------------
| Authorisation map
|--------------------------------------------------------------------------
|
| Ported from FluxCP's config/access.php. Keys are kept as the legacy
| "<module>.<action>" identifiers so that every entry can be traced straight
| back to docs/FLUXCP_FEATURE_INVENTORY.md, and so an operator migrating a
| customised access.php can see exactly which line became which.
|
| 'routes' gates whole endpoints and is enforced by middleware.
| 'abilities' gates operations within an endpoint and is enforced by gates.
|
| Unlike the legacy panel, a route with no entry here is DENIED, not served
| to everyone. See docs/MIGRATION_DECISIONS.md (D3).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | rAthena group_id to panel level
    |--------------------------------------------------------------------------
    |
    | rAthena decides what a group_id means in its own conf/groups.conf. The
    | panel only needs the privilege tier, so each ID is mapped onto one here.
    | An unmapped ID is treated as a plain player, which is the safe default and
    | matches the legacy behaviour of AccountLevel::getGroupLevel().
    |
    */

    'account_groups' => [
        0 => ['name' => 'Player',          'level' => AccountLevel::Player],
        1 => ['name' => 'Super Player',    'level' => AccountLevel::Player],
        2 => ['name' => 'Support',         'level' => AccountLevel::JuniorGameMaster],
        3 => ['name' => 'Script Manager',  'level' => AccountLevel::JuniorGameMaster],
        4 => ['name' => 'Event Manager',   'level' => AccountLevel::JuniorGameMaster],
        5 => ['name' => 'VIP',             'level' => AccountLevel::Player],
        10 => ['name' => 'Law Enforcement', 'level' => AccountLevel::SeniorGameMaster],
        99 => ['name' => 'Admin',           'level' => AccountLevel::Administrator],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route permissions
    |--------------------------------------------------------------------------
    */

    'routes' => [

        // account
        'account.cart' => AccountLevel::Player,
        'account.changemail' => AccountLevel::Player,
        'account.changepass' => AccountLevel::Player,
        'account.changesex' => AccountLevel::Player,
        'account.confirm' => AccountLevel::Unauthenticated,
        'account.confirmemail' => AccountLevel::Player,
        'account.create' => AccountLevel::Unauthenticated,
        'account.edit' => AccountLevel::Administrator,
        'account.index' => AccountLevel::JuniorGameMaster,
        'account.login' => AccountLevel::Unauthenticated,
        'account.logout' => AccountLevel::Player,
        'account.prune' => AccountLevel::Anyone,
        'account.resend' => AccountLevel::Unauthenticated,
        'account.resetpass' => AccountLevel::Unauthenticated,
        'account.resetpw' => AccountLevel::Unauthenticated,
        'account.transfer' => AccountLevel::Player,
        'account.view' => AccountLevel::Player,
        'account.xferlog' => AccountLevel::Player,

        // auction
        'auction.index' => AccountLevel::JuniorGameMaster,

        // buyingstore
        'buyingstore.index' => AccountLevel::Anyone,
        'buyingstore.viewshop' => AccountLevel::Anyone,

        // captcha
        'captcha.index' => AccountLevel::Anyone,

        // castle
        'castle.index' => AccountLevel::Anyone,

        // character
        'character.changeslot' => AccountLevel::Player,
        'character.divorce' => AccountLevel::Player,
        'character.index' => AccountLevel::JuniorGameMaster,
        'character.mapstats' => AccountLevel::Anyone,
        'character.online' => AccountLevel::Anyone,
        'character.prefs' => AccountLevel::Player,
        'character.resetlook' => AccountLevel::Player,
        'character.resetpos' => AccountLevel::Player,
        'character.view' => AccountLevel::Player,

        // cplog
        'cplog.ban' => AccountLevel::Administrator,
        'cplog.changemail' => AccountLevel::Administrator,
        'cplog.changepass' => AccountLevel::Administrator,
        'cplog.create' => AccountLevel::Administrator,
        'cplog.index' => AccountLevel::Administrator,
        'cplog.ipban' => AccountLevel::Administrator,
        'cplog.login' => AccountLevel::Administrator,
        'cplog.paypal' => AccountLevel::Administrator,
        'cplog.resetpass' => AccountLevel::Administrator,
        'cplog.txnview' => AccountLevel::Administrator,

        // donate
        'donate.complete' => AccountLevel::Anyone,
        'donate.history' => AccountLevel::Player,
        'donate.index' => AccountLevel::Anyone,
        'donate.notify' => AccountLevel::Anyone,
        'donate.trusted' => AccountLevel::Player,
        'donate.update' => AccountLevel::Anyone,

        // economy
        'economy.index' => AccountLevel::Player,

        // guild
        'guild.emblem' => AccountLevel::Anyone,
        'guild.export' => AccountLevel::Administrator,
        'guild.index' => AccountLevel::JuniorGameMaster,
        'guild.view' => AccountLevel::Player,

        // history
        'history.cplogin' => AccountLevel::Player,
        'history.emailchange' => AccountLevel::Player,
        'history.gamelogin' => AccountLevel::Player,
        'history.index' => AccountLevel::Player,
        'history.passchange' => AccountLevel::Player,
        'history.passreset' => AccountLevel::Player,

        // ipban
        'ipban.add' => AccountLevel::Administrator,
        'ipban.edit' => AccountLevel::Administrator,
        'ipban.index' => AccountLevel::Administrator,
        'ipban.remove' => AccountLevel::Administrator,
        'ipban.unban' => AccountLevel::Administrator,

        // item
        'item.index' => AccountLevel::Anyone,
        'item.iteminfo' => AccountLevel::Administrator,
        'item.view' => AccountLevel::Anyone,

        // itemshop
        'itemshop.add' => AccountLevel::Administrator,
        'itemshop.delete' => AccountLevel::Administrator,
        'itemshop.edit' => AccountLevel::Administrator,
        'itemshop.imagedel' => AccountLevel::Administrator,

        // logdata
        'logdata.branch' => AccountLevel::Administrator,
        'logdata.cashpoints' => AccountLevel::Administrator,
        'logdata.char' => AccountLevel::Administrator,
        'logdata.chat' => AccountLevel::Administrator,
        'logdata.command' => AccountLevel::Administrator,
        'logdata.feeding' => AccountLevel::Administrator,
        'logdata.index' => AccountLevel::Administrator,
        'logdata.inter' => AccountLevel::Administrator,
        'logdata.login' => AccountLevel::Administrator,
        'logdata.mvp' => AccountLevel::Administrator,
        'logdata.npc' => AccountLevel::Administrator,
        'logdata.pick' => AccountLevel::Administrator,
        'logdata.zeny' => AccountLevel::Administrator,

        // mail
        'mail.index' => AccountLevel::Administrator,

        // main
        'main.index' => AccountLevel::Anyone,
        'main.page_not_found' => AccountLevel::Anyone,

        // monster
        'monster.index' => AccountLevel::Anyone,
        'monster.view' => AccountLevel::Anyone,

        // news
        'news.add' => AccountLevel::Administrator,
        'news.delete' => AccountLevel::Administrator,
        'news.edit' => AccountLevel::Administrator,
        'news.index' => AccountLevel::Anyone,
        'news.manage' => AccountLevel::Administrator,
        'news.view' => AccountLevel::Anyone,

        // pages
        'pages.add' => AccountLevel::Administrator,
        'pages.content' => AccountLevel::Anyone,
        'pages.delete' => AccountLevel::Administrator,
        'pages.edit' => AccountLevel::Administrator,
        'pages.index' => AccountLevel::Administrator,

        // purchase
        'purchase.add' => AccountLevel::Anyone,
        'purchase.cart' => AccountLevel::Player,
        'purchase.checkout' => AccountLevel::Player,
        'purchase.clear' => AccountLevel::Player,
        'purchase.index' => AccountLevel::Anyone,
        'purchase.pending' => AccountLevel::Player,
        'purchase.remove' => AccountLevel::Player,

        // ranking
        'ranking.alchemist' => AccountLevel::Anyone,
        'ranking.blacksmith' => AccountLevel::Anyone,
        'ranking.character' => AccountLevel::Anyone,
        'ranking.death' => AccountLevel::Anyone,
        'ranking.guild' => AccountLevel::Anyone,
        'ranking.homunculus' => AccountLevel::Anyone,
        'ranking.mvp' => AccountLevel::Anyone,
        'ranking.zeny' => AccountLevel::Anyone,

        // server
        'server.info' => AccountLevel::Anyone,
        'server.status' => AccountLevel::Anyone,
        'server.status-xml' => AccountLevel::Anyone,

        // service
        'service.tos' => AccountLevel::Anyone,

        // servicedesk
        'servicedesk.catcontrol' => AccountLevel::SeniorGameMaster,
        'servicedesk.create' => AccountLevel::Player,
        'servicedesk.index' => AccountLevel::Player,
        'servicedesk.staffindex' => AccountLevel::JuniorGameMaster,
        'servicedesk.staffsettings' => AccountLevel::JuniorGameMaster,
        'servicedesk.staffview' => AccountLevel::JuniorGameMaster,
        'servicedesk.staffviewclosed' => AccountLevel::JuniorGameMaster,
        'servicedesk.view' => AccountLevel::Player,

        // unauthorized
        'unauthorized.index' => AccountLevel::Anyone,

        // vending
        'vending.index' => AccountLevel::Anyone,
        'vending.viewshop' => AccountLevel::Anyone,

        // webcommands
        'webcommands.index' => AccountLevel::Administrator,

        // woe
        'woe.custom' => AccountLevel::Anyone,
        'woe.index' => AccountLevel::Anyone,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route permissions with no legacy equivalent
    |--------------------------------------------------------------------------
    |
    | Endpoints this port adds. They are listed separately so that the block
    | above stays a faithful record of FluxCP's access.php and it is obvious
    | which entries are new.
    |
    | Because unmapped routes are denied (D3), anything added here has to be
    | declared -- a new endpoint cannot quietly become public.
    |
    */

    'added_routes' => [
        // The signed-in account's own characters, for the client's character
        // list. The legacy panel folded this into account/view's template.
        'character.mine' => AccountLevel::Player,

        // How many characters there are of each job class, for the class
        // showcase block. A public aggregate; no individual character is
        // identifiable from it.
        'character.classes' => AccountLevel::Anyone,

        // Aggregate server counts for the statistics block. The legacy
        // server.info action covered server information generally, but this
        // is a distinct endpoint rather than a reinterpretation of it.
        'server.statistics' => AccountLevel::Anyone,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ability permissions
    |--------------------------------------------------------------------------
    |
    | FluxCP read these through $auth->allowedTo<Name> magic getters. The names
    | are preserved verbatim so the inventory remains directly comparable.
    |
    | Five of them -- SeeAccountPassword, SearchMD5Passwords, SeeCpLoginLogPass,
    | SeeCpResetPass, SeeCpChangePass -- guard password columns this port no
    | longer writes (D2). They stay pinned to Noone so that a panel pointed at a
    | legacy database cannot surface historical rows.
    |
    */

    'abilities' => [
        'AddShopItem' => AccountLevel::Administrator,
        'AvoidSexChangeCost' => AccountLevel::JuniorGameMaster,
        'BanHigherPower' => AccountLevel::Noone,
        'ChangeSlot' => AccountLevel::JuniorGameMaster,
        'DeleteAccount' => AccountLevel::Administrator,
        'DeleteCharacter' => AccountLevel::Administrator,
        'DeleteShopItem' => AccountLevel::Administrator,
        'DivorceCharacter' => AccountLevel::JuniorGameMaster,
        'Donate' => AccountLevel::Player,
        'EditAccountBalance' => AccountLevel::Administrator,
        'EditAccountGroupID' => AccountLevel::Administrator,
        'EditHigherPower' => AccountLevel::Noone,
        'EditShopItem' => AccountLevel::Administrator,
        'HideFromZenyRank' => AccountLevel::Player,
        'IgnoreHiddenPref' => AccountLevel::JuniorGameMaster,
        'IgnoreHiddenPref2' => AccountLevel::JuniorGameMaster,
        'ModifyAccountPrefs' => AccountLevel::Administrator,
        'ModifyCharPrefs' => AccountLevel::Administrator,
        'ModifyIpBan' => AccountLevel::Administrator,
        'PermBanAccount' => AccountLevel::SeniorGameMaster,
        'PermUnbanAccount' => AccountLevel::SeniorGameMaster,
        'RemoveIpBan' => AccountLevel::Administrator,
        'ResetLook' => AccountLevel::JuniorGameMaster,
        'ResetPosition' => AccountLevel::JuniorGameMaster,
        'SearchCpChangePass' => AccountLevel::Noone,
        'SearchCpLoginLogPw' => AccountLevel::Noone,
        'SearchCpResetPass' => AccountLevel::Noone,
        'SearchMD5Passwords' => AccountLevel::Noone,
        'SearchWhosOnline' => AccountLevel::Anyone,
        'SeeAccountID' => AccountLevel::JuniorGameMaster,
        'SeeAccountPassword' => AccountLevel::Noone,
        'SeeCpChangePass' => AccountLevel::Noone,
        'SeeCpLoginLogPass' => AccountLevel::Noone,
        'SeeCpResetPass' => AccountLevel::Noone,
        'SeeHiddenMapStats' => AccountLevel::JuniorGameMaster,
        'SeeItemDb2Scripts' => AccountLevel::Administrator,
        'SeeItemDbScripts' => AccountLevel::Anyone,
        'SeeUnknownItems' => AccountLevel::JuniorGameMaster,
        'TempBanAccount' => AccountLevel::JuniorGameMaster,
        'TempUnbanAccount' => AccountLevel::JuniorGameMaster,
        'ViewAccount' => AccountLevel::SeniorGameMaster,
        'ViewAccountBanLog' => AccountLevel::SeniorGameMaster,
        'ViewCharacter' => AccountLevel::SeniorGameMaster,
        'ViewGuild' => AccountLevel::Administrator,
        'ViewOnlinePosition' => AccountLevel::JuniorGameMaster,
        'ViewRawTxnLogData' => AccountLevel::Administrator,
        'ViewWoeDisallowed' => AccountLevel::JuniorGameMaster,
    ],

];
