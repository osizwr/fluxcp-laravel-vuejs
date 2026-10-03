<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountHistoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\CharacterManagementController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MonsterController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ServerStatisticsController;
use App\Http\Controllers\Api\ServerStatusController;
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
    Route::get('news/{article}', [NewsController::class, 'show'])
        ->whereNumber('article')
        ->name('news.view');

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
