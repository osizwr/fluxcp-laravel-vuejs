<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ProbesServerReachability;
use App\Events\ServerStatusUpdated;
use App\Services\Server\ServerStatusService;
use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Console\Command;

/**
 * Measures server status and broadcasts it.
 *
 * Run on a schedule. The cached figures are discarded first so the broadcast
 * carries a fresh measurement rather than re-reading its own cache, and the
 * fresh result then repopulates the cache, which means the REST endpoint serves
 * the same numbers without probing again.
 */
final class BroadcastServerStatus extends Command
{
    protected $signature = 'panel:broadcast-server-status';

    protected $description = 'Measure game server status and broadcast it to connected clients';

    public function handle(
        ServerRegistry $registry,
        ProbesServerReachability $probe,
        ServerStatusService $status,
    ): int {
        foreach ($registry->all() as $group) {
            $probe->forget($group->loginServer->endpoint());

            foreach ($group->charMapServers as $pair) {
                /** @var CharMapServer $pair */
                $probe->forget($pair->charServer);
                $probe->forget($pair->mapServer);
            }
        }

        $groups = $status->all();

        ServerStatusUpdated::dispatch($groups);

        $this->components->info(sprintf(
            'Broadcast status for %d server group(s), %s player(s) online.',
            $groups->count(),
            number_format($groups->sum(fn ($group): int => $group->totalPlayersOnline())),
        ));

        return self::SUCCESS;
    }
}
