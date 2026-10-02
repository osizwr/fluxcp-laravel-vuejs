<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Support\Rathena\ServerRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\RathenaTestSchema;

/**
 * Gives a test a working set of rAthena tables.
 *
 * The schema is built once per process rather than per test, because MySQL
 * DDL is slow and the structure never varies. Between tests the rows are
 * cleared instead, which is what actually needs to be isolated. Laravel's
 * RefreshDatabase trait cannot do this job: it only knows about the
 * application's own connections, and these are registered at runtime.
 */
trait InteractsWithRathena
{
    private static bool $rathenaSchemaBuilt = false;

    protected function setUpInteractsWithRathena(): void
    {
        $registry = $this->app->make(ServerRegistry::class);
        $group = $registry->default();

        $login = $group->loginConnection();
        $charMap = $group->charMapConnection();
        $logs = $group->logsConnection();

        if (! self::$rathenaSchemaBuilt) {
            RathenaTestSchema::create($login, $charMap, $logs);
            self::$rathenaSchemaBuilt = true;
        }

        $this->clearRathenaTables([$login, $charMap, $logs]);
    }

    /**
     * Empty every table behind these connections, leaving the structure.
     *
     * Foreign key checks are disabled for the duration because rAthena's
     * schema has no foreign keys but a test database may carry some from
     * earlier experimentation, and truncate order should not matter here.
     *
     * @param  list<string>  $connections
     */
    protected function clearRathenaTables(array $connections): void
    {
        $seen = [];

        foreach ($connections as $connectionName) {
            $connection = DB::connection($connectionName);
            $database = $connection->getDatabaseName();

            if (in_array($database, $seen, strict: true)) {
                continue;
            }

            $seen[] = $database;

            $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($connection->getSchemaBuilder()->getTableListing() as $table) {
                $connection->table($table)->truncate();
            }

            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The server group under test.
     */
    protected function serverGroup(): \App\Support\Rathena\ServerGroup
    {
        return $this->app->make(ServerRegistry::class)->current();
    }
}
