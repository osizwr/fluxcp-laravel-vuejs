<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Rathena\Schema\PanelSchema;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Creates the panel's own tables inside rAthena's databases.
 *
 * This is the replacement for FluxCP's web installer, which checked a
 * directory of marker files on every single request and force-redirected to
 * itself when anything was outstanding. Schema state is a deployment concern
 * here, so it is a command.
 *
 * It is deliberately additive. A table that already exists is left exactly as
 * it is: never dropped, never altered. Pointing this at a database that
 * already carries a FluxCP schema is safe, and so is running it twice.
 */
final class InstallPanelSchema extends Command
{
    protected $signature = 'panel:install-schema
                            {--group= : Only install for this server group}
                            {--pretend : Report what would be created without creating it}';

    protected $description = "Create the panel's tables in the configured rAthena databases";

    public function handle(ServerRegistry $registry): int
    {
        $groups = $this->resolveGroups($registry);

        if ($groups === []) {
            $this->components->error('No matching server group.');

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $created = 0;
        $existing = 0;

        foreach ($groups as $group) {
            $this->components->info("Server group: {$group->name} ({$group->key})");

            try {
                [$groupCreated, $groupExisting] = $this->installForGroup($group, $pretend);
            } catch (Throwable $e) {
                $this->components->error("Could not reach the databases for '{$group->key}': {$e->getMessage()}");

                return self::FAILURE;
            }

            $created += $groupCreated;
            $existing += $groupExisting;
        }

        $this->newLine();

        if ($pretend) {
            $this->components->info("Would create {$created} table(s); {$existing} already present.");

            return self::SUCCESS;
        }

        $this->components->info("Created {$created} table(s); {$existing} already present.");

        return self::SUCCESS;
    }

    /**
     * @return list<ServerGroup>
     */
    private function resolveGroups(ServerRegistry $registry): array
    {
        $only = $this->option('group');

        if ($only === null) {
            return $registry->all()->values()->all();
        }

        return $registry->has((string) $only)
            ? [$registry->get((string) $only)]
            : [];
    }

    /**
     * @return array{int, int} Created and already-present counts.
     */
    private function installForGroup(ServerGroup $group, bool $pretend): array
    {
        $created = 0;
        $existing = 0;

        $targets = [
            ['login database', $group->loginConnection(), PanelSchema::loginTables()],
        ];

        /*
         * Every char/map pair gets its own copy of the char/map tables,
         * because pairs may use separate character databases. When they share
         * one, the second pass finds the tables already present and leaves
         * them alone.
         */
        foreach ($group->charMapServers as $pair) {
            $targets[] = [
                "char/map database ({$pair->name})",
                $pair->connectionName(),
                PanelSchema::charMapTables(),
            ];
        }

        foreach ($targets as [$label, $connection, $tables]) {
            $builder = Schema::connection($connection);
            $database = $builder->getConnection()->getDatabaseName();

            $this->line("  <fg=gray>{$label}:</> {$database}");

            foreach ($tables as $name => $definition) {
                if ($builder->hasTable($name)) {
                    $existing++;
                    $this->line("    <fg=gray>exists</>  {$name}");

                    continue;
                }

                $created++;

                if ($pretend) {
                    $this->line("    <fg=yellow>pending</> {$name}");

                    continue;
                }

                $builder->create($name, $definition);
                $this->line("    <fg=green>created</> {$name}");
            }
        }

        return [$created, $existing];
    }
}
