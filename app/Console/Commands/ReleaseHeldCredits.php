<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Donations\DonationService;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Console\Command;

/**
 * Releases donation credits whose hold window has passed, and cancels any
 * payment that was reversed inside it.
 *
 * Ports Flux::processHeldCredits(), which the legacy exposed as
 * `donate/update` -- an HTTP endpoint guarded by comparing a query parameter
 * against the installer password. Crediting accounts is not something a web
 * request should be able to ask for, so it is a scheduled command here, the
 * same treatment `account/prune` got.
 *
 * ---------------------------------------------------------------------------
 * Why the hold exists
 * ---------------------------------------------------------------------------
 *
 * A payment from an address the server has not seen before is recorded but not
 * credited. If it is reversed inside the window -- which is what a stolen card
 * looks like -- the hold is cancelled and nothing was ever spendable. If the
 * window passes, the credits are added and the payer is marked trusted, so
 * their next donation is immediate.
 *
 * Without it, a chargeback leaves the server having delivered items for money
 * it no longer has, and no way to take them back.
 */
final class ReleaseHeldCredits extends Command
{
    protected $signature = 'panel:release-held-credits
                            {--group=* : Server groups to process, defaults to all}
                            {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Release donation credits past their hold window, and cancel reversed payments';

    public function handle(ServerRegistry $servers, DonationService $donations): int
    {
        if (config('panel.donations.enabled') !== true) {
            $this->components->warn(
                'Donations are not enabled, so there is nothing to release. '
                .'Set PANEL_DONATIONS_ENABLED=true if that is wrong.',
            );

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            /*
             * There is no read-only form of this: deciding whether a payment
             * was reversed and acting on it is the same pass. Rather than
             * write a second, subtly different query that could disagree with
             * the real one, the dry run reports the queue and does nothing.
             */
            return $this->reportQueue($servers, $donations);
        }

        $requested = array_filter((array) $this->option('group'));

        $groups = $requested === []
            ? $servers->all()
            : array_map(fn (string $key): ServerGroup => $servers->get($key), $requested);

        $released = 0;
        $cancelled = 0;
        $credits = 0;

        foreach ($groups as $group) {
            $servers->use($group->key);

            $result = $donations->processHeldPayments($group);

            $released += $result['released'];
            $cancelled += $result['cancelled'];
            $credits += $result['credits'];

            $this->line(sprintf(
                '  <fg=gray>%s:</> %d released (%d credits), %d cancelled',
                $group->key,
                $result['released'],
                $result['credits'],
                $result['cancelled'],
            ));
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%d payment(s) released adding %d credits; %d cancelled after reversal.',
            $released,
            $credits,
            $cancelled,
        ));

        return self::SUCCESS;
    }

    private function reportQueue(ServerRegistry $servers, DonationService $donations): int
    {
        foreach ($servers->all() as $group) {
            $servers->use($group->key);

            $held = $donations->heldPaymentCount($group);

            $this->line("  <fg=yellow>{$group->key}: {$held} payment(s) on hold</>");
        }

        $this->components->warn('Dry run: nothing was released or cancelled.');

        return self::SUCCESS;
    }
}
