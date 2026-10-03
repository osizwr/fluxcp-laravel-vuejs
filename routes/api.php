<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountAdminController;
use App\Http\Controllers\Api\AccountHistoryController;
use App\Http\Controllers\Api\AdminSearchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\CharacterManagementController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\IpBanController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LogBrowserController;
use App\Http\Controllers\Api\MonsterController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlayerShopController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ServerStatisticsController;
use App\Http\Controllers\Api\ServerStatusController;
use App\Http\Controllers\Api\ServerStatusXmlController;
use App\Http\Controllers\Api\StaticPageController;
use App\Http\Controllers\Api\WorldController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Route names are the legacy "<module>.<action>" identifiers, so each endpoint
| traces straight back to docs/FLUXCP_FEATURE_INVENTORY.md and to its entry in
| config/permissions.php.
|
| Every route carries the `permission` middleware, which refuses anything whose
| name has no entry in that map. A new endpoint therefore cannot become
| publicly reachable by omission, which is how ten legacy actions ended up
| served to anyone. See docs/MIGRATION_DECISIONS.md (D3).
|
*/

Route::middleware('permission')->group(function (): void {

    /*
     * Authentication. Sign-in is guests-only and sign-out requires a session,
     * which the permission map expresses as UNAUTH and NORMAL respectively.
     */
    Route::post('auth/login', [AuthController::class, 'store'])->name('account.login');
    Route::post('auth/logout', [AuthController::class, 'destroy'])->name('account.logout');

    /*
     * The signed-in account. Named account.view because that is the legacy
     * action which rendered an account's own details.
     */
    Route::get('account', [AuthController::class, 'show'])->name('account.view');

    /*
     * Registration and e-mail confirmation.
     *
     * All three are guests-only, which is the legacy access level. Confirming
     * is included deliberately: an account awaiting confirmation cannot sign
     * in, so the person following the link is necessarily a guest.
     */
    Route::post('auth/register', [RegistrationController::class, 'store'])
        ->name('account.create');

    Route::post('auth/confirm', [RegistrationController::class, 'confirm'])
        ->name('account.confirm');

    Route::post('auth/confirm/resend', [RegistrationController::class, 'resend'])
        ->name('account.resend');

    /*
     * Password reset. Two steps: ask for a link, then use it.
     *
     * The route names are the legacy ones -- resetpass requested, resetpw
     * completed -- so each traces back to its entry in config/permissions.php.
     */
    Route::post('auth/password/forgot', [PasswordResetController::class, 'store'])
        ->name('account.resetpass');

    Route::post('auth/password/reset', [PasswordResetController::class, 'update'])
        ->name('account.resetpw');

    /*
     * Credentials of the signed-in account.
     */
    Route::put('account/password', [PasswordController::class, 'update'])
        ->name('account.changepass');

    Route::put('account/email', [EmailController::class, 'update'])
        ->name('account.changemail');

    Route::post('account/email/confirm', [EmailController::class, 'confirm'])
        ->name('account.confirmemail');

    /*
     * The signed-in account's own history.
     *
     * Every one of these is scoped to the session's account and takes no id
     * from the request. They hold sign-in addresses and the times somebody was
     * at a keyboard, so an endpoint that accepted an id would be a way to
     * follow another player around.
     */
    Route::get('account/history/panel-logins', [AccountHistoryController::class, 'panelLogins'])
        ->name('history.cplogin');

    Route::get('account/history/game-logins', [AccountHistoryController::class, 'gameLogins'])
        ->name('history.gamelogin');

    Route::get('account/history/password-changes', [AccountHistoryController::class, 'passwordChanges'])
        ->name('history.passchange');

    Route::get('account/history/password-resets', [AccountHistoryController::class, 'passwordResets'])
        ->name('history.passreset');

    Route::get('account/history/email-changes', [AccountHistoryController::class, 'emailChanges'])
        ->name('history.emailchange');

    /*
     * The CAPTCHA image, when the self-hosted driver is in use. Open to
     * everyone, because the forms that need it are open to guests.
     */
    Route::get('captcha', [CaptchaController::class, 'show'])->name('captcha.index');

    /*
     * Public server information.
     */
    Route::get('server/status', [ServerStatusController::class, 'index'])->name('server.status');

    /*
     * The legacy machine-readable feed, kept at its original shape so server
     * listing sites, Discord bots and forum widgets already polling it keep
     * working after a migration. New consumers should use the JSON above.
     */
    Route::get('server/status.xml', ServerStatusXmlController::class)->name('server.status-xml');

    /*
     * Aggregate counts over the game tables, for the statistics block. Real
     * queries, cached: these are full-table counts behind a landing page.
     */
    Route::get('server/statistics', [ServerStatisticsController::class, 'index'])
        ->name('server.statistics');

    /*
     * Public news. Read-only -- the admin half of the legacy CMS is not built,
     * so there is no write path rather than a stub that looks like one.
     */
    Route::get('news', [NewsController::class, 'index'])->name('news.index');

    /*
     * The operator's side. Declared before the public `news/{article}` route
     * so `news/manage` is not swallowed by it -- although the numeric
     * constraint would prevent that anyway, the order makes it not depend on
     * remembering the constraint.
     */
    Route::get('news/manage', [NewsController::class, 'manage'])->name('news.manage');
    Route::post('news', [NewsController::class, 'store'])->name('news.add');
    Route::get('news/{article}/edit', [NewsController::class, 'edit'])
        ->whereNumber('article')
        ->name('news.edit');
    Route::put('news/{article}', [NewsController::class, 'update'])
        ->whereNumber('article')
        ->name('news.update');
    Route::delete('news/{article}', [NewsController::class, 'destroy'])
        ->whereNumber('article')
        ->name('news.delete');

    Route::get('news/{article}', [NewsController::class, 'show'])
        ->whereNumber('article')
        ->name('news.view');

    /*
     * Operator-authored static pages.
     *
     * `pages/{path}` is public and reached by path rather than id, so a page
     * keeps a stable URL across edits. The rest is the administrator's side.
     */
    Route::get('pages', [StaticPageController::class, 'index'])->name('pages.index');
    Route::post('pages', [StaticPageController::class, 'store'])->name('pages.add');
    Route::put('pages/{page}', [StaticPageController::class, 'update'])
        ->whereNumber('page')
        ->name('pages.edit');
    Route::delete('pages/{page}', [StaticPageController::class, 'destroy'])
        ->whereNumber('page')
        ->name('pages.delete');

    Route::get('pages/{path}', [StaticPageController::class, 'show'])
        ->where('path', '[A-Za-z0-9][A-Za-z0-9\-\/]*')
        ->name('pages.content');

    /*
     * Staff search and account editing.
     *
     * The legacy account search accepted a `password` parameter and matched it
     * against `login.user_pass`. It is not ported: on a server storing
     * cleartext that is a way to find every account sharing a password, and to
     * confirm a guess against the whole player base at once.
     * See docs/MIGRATION_DECISIONS.md (D22).
     */
    Route::get('admin/accounts', [AdminSearchController::class, 'accounts'])
        ->name('account.index');

    Route::put('admin/accounts/{account}', [AccountAdminController::class, 'update'])
        ->whereNumber('account')
        ->name('account.edit');

    Route::get('admin/characters', [AdminSearchController::class, 'characters'])
        ->name('character.index');

    /*
     * The log browsers.
     *
     * One endpoint for all twenty views, which the legacy had as twenty-two
     * near-identical modules. Each view declares its own minimum level in
     * config/log_browsers.php on top of this route's: the item log is everyday
     * moderation, the chat log is every private message players have sent.
     */
    Route::get('logs', [LogBrowserController::class, 'index'])->name('logdata.index');
    Route::get('logs/{view}', [LogBrowserController::class, 'show'])
        ->where('view', '[a-z\-]+')
        ->name('logdata.view');

    /*
     * IP bans.
     *
     * These write rAthena's own `ipbanlist`, which the login server reads, so
     * they affect the game as well as the panel. Each write needs the matching
     * ability on top of the route's staff level.
     *
     * The pattern is part of the path, so it is URL-encoded by the client --
     * `203.0.113.*` contains no reserved characters, but encoding it keeps the
     * route from depending on that.
     */
    Route::get('ip-bans', [IpBanController::class, 'index'])->name('ipban.index');
    Route::post('ip-bans', [IpBanController::class, 'store'])->name('ipban.add');
    Route::put('ip-bans/{pattern}', [IpBanController::class, 'update'])->name('ipban.edit');
    Route::delete('ip-bans', [IpBanController::class, 'destroy'])->name('ipban.unban');

    /*
     * Castle ownership and the siege schedule.
     *
     * The schedule is reported per world with its timezone and the next
     * absolute start, because "Saturday 20:00" without saying whose 20:00 is
     * the usual reason players turn up an hour out.
     */
    Route::get('world/castles', [WorldController::class, 'castles'])->name('castle.index');
    Route::get('world/siege-schedule', [WorldController::class, 'siegeSchedule'])->name('woe.index');

    /*
     * Player shops. Vending stalls and buying stores share an implementation
     * because they differ only in which tables they read.
     */
    Route::get('shops/{kind}', [PlayerShopController::class, 'index'])
        ->whereIn('kind', ['vending', 'buying'])
        ->name('vending.index');

    Route::get('shops/{kind}/{shop}', [PlayerShopController::class, 'show'])
        ->whereIn('kind', ['vending', 'buying'])
        ->whereNumber('shop')
        ->name('vending.viewshop');

    /*
     * Guilds. The emblem is a PNG decoded from the gzip-compressed BMP
     * rAthena stores as hex, and 404s rather than serving a placeholder when
     * a guild has none -- the client decides what to draw instead, and a
     * placeholder behind a 200 cannot be told apart from a real emblem by a
     * cache.
     */
    Route::get('guilds', [GuildController::class, 'index'])->name('guild.index');

    Route::get('guilds/{guild}', [GuildController::class, 'show'])
        ->whereNumber('guild')
        ->name('guild.view');

    Route::get('guilds/{guild}/emblem', [GuildController::class, 'emblem'])
        ->whereNumber('guild')
        ->name('guild.emblem');

    Route::get('guilds/{guild}/members.csv', [GuildController::class, 'export'])
        ->whereNumber('guild')
        ->name('guild.export');

    /*
     * The item and monster databases.
     *
     * Both read through the merge service, so a server's own item_db2 and
     * mob_db2 entries appear with their custom stats rather than the stock
     * ones (D6).
     *
     * `item.iteminfo` is the legacy name for the filter vocabulary the search
     * form is built from. It is held at ADMIN in the permission map, matching
     * the legacy access level.
     */
    Route::get('items', [ItemController::class, 'index'])->name('item.index');

    Route::get('items/vocabulary', [ItemController::class, 'vocabulary'])
        ->name('item.iteminfo');

    Route::get('items/{item}', [ItemController::class, 'show'])
        ->whereNumber('item')
        ->name('item.view');

    Route::get('monsters', [MonsterController::class, 'index'])->name('monster.index');

    Route::get('monsters/{monster}', [MonsterController::class, 'show'])
        ->whereNumber('monster')
        ->name('monster.view');

    /*
     * Public ladders.
     */
    Route::get('rankings/level', [RankingController::class, 'byLevel'])->name('ranking.character');
    Route::get('rankings/zeny', [RankingController::class, 'byZeny'])->name('ranking.zeny');

    /*
     * The fame ladders. One route for both branches, because alchemist and
     * blacksmith differ only in which class ids count.
     */
    Route::get('rankings/alchemist', [RankingController::class, 'byFame'])
        ->defaults('branch', 'alchemist')
        ->name('ranking.alchemist');

    Route::get('rankings/blacksmith', [RankingController::class, 'byFame'])
        ->defaults('branch', 'blacksmith')
        ->name('ranking.blacksmith');

    Route::get('rankings/deaths', [RankingController::class, 'byDeaths'])->name('ranking.death');
    Route::get('rankings/homunculus', [RankingController::class, 'byHomunculus'])
        ->name('ranking.homunculus');
    Route::get('rankings/guilds', [RankingController::class, 'byGuild'])->name('ranking.guild');
    Route::get('rankings/mvp', [RankingController::class, 'byMvp'])->name('ranking.mvp');

    /*
     * Characters. The who-is-online listing is refused while War of Emperium
     * is running, because it reveals where characters are and would let guilds
     * scout castle defences from the website during a siege.
     */
    Route::get('characters/online', [CharacterController::class, 'online'])
        ->middleware('not-during-woe')
        ->name('character.online');

    Route::get('characters/mine', [CharacterController::class, 'mine'])->name('character.mine');

    /*
     * How many players are on each map. Public, as in the legacy panel, and
     * it honours the per-character "hide my map" preference.
     */
    Route::get('characters/maps', [CharacterManagementController::class, 'mapStatistics'])
        ->name('character.mapstats');

    /*
     * One character, and the maintenance a player may do to it.
     *
     * Each of these refuses while the character is online. rAthena holds the
     * character in memory and writes it back on logout, so a change made
     * meanwhile is silently reverted.
     *
     * Acting on somebody else's character needs the matching ability; acting
     * on your own never does.
     */
    Route::get('characters/{character}', [CharacterManagementController::class, 'show'])
        ->whereNumber('character')
        ->name('character.view');

    Route::put('characters/{character}/slot', [CharacterManagementController::class, 'changeSlot'])
        ->whereNumber('character')
        ->name('character.changeslot');

    Route::post('characters/{character}/reset-look', [CharacterManagementController::class, 'resetLook'])
        ->whereNumber('character')
        ->name('character.resetlook');

    Route::post('characters/{character}/reset-position', [CharacterManagementController::class, 'resetPosition'])
        ->whereNumber('character')
        ->name('character.resetpos');

    Route::post('characters/{character}/divorce', [CharacterManagementController::class, 'divorce'])
        ->whereNumber('character')
        ->name('character.divorce');

    Route::match(['get', 'put'], 'characters/{character}/preferences', [CharacterManagementController::class, 'preferences'])
        ->whereNumber('character')
        ->name('character.prefs');

    /*
     * Character counts per job class, for the class showcase. A public
     * aggregate from which no individual character is identifiable.
     */
    Route::get('characters/classes', [ServerStatisticsController::class, 'classes'])
        ->name('character.classes');

});
