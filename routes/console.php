<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| The legacy panel did this work inline, from a hook that ran before every
| single request: it probed every game server on every page view, and pruned
| accounts and released held credits as a side effect of somebody loading a
| page. That made page load time depend on how long a firewalled port took to
| time out, and meant the maintenance jobs ran only as often as the site
| happened to be visited.
|
*/

/*
 * Status is measured and broadcast on a short cycle. withoutOverlapping keeps a
 * run that is waiting on an unreachable server from stacking up behind the next
 * one.
 */
Schedule::command('panel:broadcast-server-status')
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->runInBackground();
