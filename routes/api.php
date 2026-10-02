<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\RankingController;
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
     * Public server information.
     */
    Route::get('server/status', [ServerStatusController::class, 'index'])->name('server.status');

    /*
     * Public ladders.
     */
    Route::get('rankings/level', [RankingController::class, 'byLevel'])->name('ranking.character');
    Route::get('rankings/zeny', [RankingController::class, 'byZeny'])->name('ranking.zeny');

    /*
     * Characters. The who-is-online listing is refused while War of Emperium
     * is running, because it reveals where characters are and would let guilds
     * scout castle defences from the website during a siege.
     */
    Route::get('characters/online', [CharacterController::class, 'online'])
        ->middleware('not-during-woe')
        ->name('character.online');

    Route::get('characters/mine', [CharacterController::class, 'mine'])->name('character.mine');

});
