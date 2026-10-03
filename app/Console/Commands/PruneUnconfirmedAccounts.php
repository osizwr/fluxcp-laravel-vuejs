<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountState;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Deletes registrations that were never confirmed and whose window has passed.
 *
 * Ports Flux::pruneUnconfirmedAccounts(), which the legacy panel ran from
 * modules/account/prune.php -- an HTTP endpoint guarded by comparing a query
 * parameter against the installer password. This is a scheduled command
 * instead, so deleting accounts is not something a web request can ask for.
 *
 * ---------------------------------------------------------------------------
 * Why this is deliberately more careful than the legacy query
 * ---------------------------------------------------------------------------
 *
 * The legacy version was a single multi-table DELETE keyed on `confirmed = 0`,
 * `confirm_code IS NOT NULL` and `confirm_expire <= NOW()`. That is nearly
 * right, and nearly right is not good enough for a statement that deletes
 * accounts: anything that set `confirmed` to 0 on a live account -- a botched
 * admin tool, a restored backup, a hand-written UPDATE -- would hand that
 * account to this query.
 *
 * So three further conditions are required before a row is deleted:
 *
 *   - `login.state` must still be 5, the held state. An account that has been
 *     confirmed, or manually released, is not a candidate however its audit
 *     row reads.
 *   - the account must own no characters. An account that never completed
 *     confirmation cannot have logged in to create one, so a character is
 *     proof that this is not what it appears to be.
 *   - the account must not be staff.
 *
 * Each is redundant if the data is consistent. They are here for when it is
 * not, because the cost of being wrong is somebody's account.
 */
final class PruneUnconfirmedAccounts extends Command
{
    protected $signature = 'panel:prune-unconfirmed
                            {--group=* : Server groups to prune, defaults to all}
                            {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete registrations whose e-mail confirmation window has passed';

    public function handle(
        ServerRegistry $servers,
        ConnectionResolverInterface $connections,
    ): int {
        if (config('panel.maintenance.prune_unconfirmed_accounts') !== true
            && ! $this->option('dry-run')) {
            $this->components->warn(
                'Pruning is off. Set PANEL_PRUNE_UNCONFIRMED=true to enable it, '
                .'or use --dry-run to see what it would remove.',
            );

            return self::SUCCESS;
        }

        $requested = array_filter((array) $this->option('group'));
        $groups = $requested === []
            ? $servers->all()
            : array_map(fn (string $key): ServerGroup => $servers->get($key), $requested);

        $total = 0;

        foreach ($groups as $group) {
            $total += $this->pruneGroup($group, $connections);
        }

        $this->newLine();

        $this->components->info($this->option('dry-run')
            ? "{$total} unconfirmed account(s) would be deleted."
            : "{$total} unconfirmed account(s) deleted.");

        return self::SUCCESS;
    }

    private function pruneGroup(ServerGroup $group, ConnectionResolverInterface $connections): int
    {
        $connection = $connections->connection($group->loginConnection());

        $candidates = $connection
            ->table('cp_createlog')
            ->join('login', 'login.account_id', '=', 'cp_createlog.account_id')
            ->where('cp_createlog.confirmed', 0)
            ->whereNotNull('cp_createlog.confirm_code')
            ->whereNotNull('cp_createlog.confirm_expire')
            ->where('cp_createlog.confirm_expire', '<=', now())
            // Still held. See the class comment.
            ->where('login.state', AccountState::PermanentlyBanned->value)
            ->where('login.group_id', 0)
            ->pluck('login.account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($candidates === []) {
            $this->line("  <fg=gray>{$group->key}: nothing to prune</>");

            return 0;
        }

        /*
         * Character ownership is checked on the char_map connection, which may
         * be a different database or a different server, so it cannot be a
         * join in the query above.
         */
        $withCharacters = $connections
            ->connection($group->defaultCharMapServer()->connectionName())
            ->table('char')
            ->whereIn('account_id', $candidates)
            ->distinct()
            ->pluck('account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $deletable = array_values(array_diff($candidates, $withCharacters));

        foreach ($withCharacters as $accountId) {
            $this->components->warn(
                "{$group->key}: account {$accountId} looks unconfirmed but owns characters. Left alone.",
            );
        }

        if ($deletable === []) {
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->line("  <fg=yellow>{$group->key}: would delete ".count($deletable).' account(s): '
                .implode(', ', $deletable).'</>');

            return count($deletable);
        }

        $connection->transaction(function () use ($connection, $deletable): void {
            // The audit row first, so a failure part way cannot leave a login
            // row with no record of where it came from.
            $connection->table('cp_createlog')->whereIn('account_id', $deletable)->delete();
            $connection->table('login')->whereIn('account_id', $deletable)->delete();
        });

        $this->line("  <fg=green>{$group->key}: deleted ".count($deletable).' account(s)</>');

        return count($deletable);
    }
}
