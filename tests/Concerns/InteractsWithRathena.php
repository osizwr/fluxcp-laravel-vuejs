<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\RathenaTestSchema;

/**
 * Gives a test a working set of rAthena tables.
 *
 * The schema is built once per process rather than per test, because MySQL DDL
 * is slow and the structure never varies. Between tests the rows are cleared
 * instead, which is what actually needs to be isolated. Laravel's
 * RefreshDatabase trait cannot do this job: it only knows about the
 * application's own connections, and these are registered at runtime.
 *
 * ---------------------------------------------------------------------------
 * Safety
 * ---------------------------------------------------------------------------
 *
 * This trait destroys data, so it refuses to run against anything but a
 * database whose name is on the allow-list below.
 *
 * That guard exists because of a real incident during development, not as a
 * precaution. The table listing used to be taken from
 * `getSchemaBuilder()->getTableListing()` with no schema argument. On
 * MySQL/MariaDB that returns **every table the connected user can see, across
 * every database, schema-qualified** -- 555 names on the machine in question,
 * 458 of them in unrelated databases. Truncating each name in turn therefore
 * emptied every database on the server, not just the test one.
 *
 * Two things are done about it:
 *
 *   1. The listing is scoped to the connection's own database explicitly.
 *   2. The database name is checked against an allow-list first, so a
 *      misconfigured connection fails the test instead of destroying data.
 *
 * Point 2 is the one that matters. A scoped listing is correct, but a guard is
 * what makes a future mistake loud instead of catastrophic.
 */
trait InteractsWithRathena
{
    /**
     * Databases this trait is permitted to destroy.
     *
     * A connection pointing anywhere else is a configuration fault, and the
     * test fails rather than proceeding.
     *
     * @var list<string>
     */
    private const DESTROYABLE_DATABASES = [
        'fluxcp_test',
        'fluxcp_test_logs',
    ];

    private static bool $rathenaSchemaBuilt = false;

    protected function setUpInteractsWithRathena(): void
    {
        $registry = $this->app->make(ServerRegistry::class);
        $group = $registry->default();

        $login = $group->loginConnection();
        $charMap = $group->charMapConnection();
        $logs = $group->logsConnection();

        $this->assertConnectionsAreDestroyable([$login, $charMap, $logs]);

        if (! self::$rathenaSchemaBuilt) {
            RathenaTestSchema::create($login, $charMap, $logs);
            self::$rathenaSchemaBuilt = true;
        }

        $this->clearRathenaTables([$login, $charMap, $logs]);
    }

    /**
     * Refuse to continue unless every connection points at a database this
     * trait is allowed to empty.
     *
     * @param  list<string>  $connections
     */
    protected function assertConnectionsAreDestroyable(array $connections): void
    {
        foreach ($connections as $connectionName) {
            $database = DB::connection($connectionName)->getDatabaseName();

            if (! in_array($database, self::DESTROYABLE_DATABASES, strict: true)) {
                throw new RuntimeException(
                    "Refusing to run destructive test setup against the database '{$database}' "
                    ."(connection '{$connectionName}'). Only "
                    .implode(', ', self::DESTROYABLE_DATABASES)
                    .' may be emptied. Check the RATHENA_* values in phpunit.xml.'
                );
            }
        }
    }

    /**
     * Empty every table in each distinct database behind these connections,
     * leaving the structure in place.
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

            /*
             * The schema is named explicitly. Without it the listing spans
             * every database the user can reach, and the names come back
             * qualified, so truncating them reaches outside this database
             * entirely. See the note on this trait.
             */
            $tables = $connection->getSchemaBuilder()->getTableListing(
                schema: $database,
                schemaQualified: false,
            );

            $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                // Belt and braces: a qualified name must never reach truncate.
                if (str_contains($table, '.')) {
                    throw new RuntimeException(
                        "Refusing to truncate the schema-qualified table '{$table}'."
                    );
                }

                $connection->table($table)->truncate();
            }

            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The server group under test.
     */
    protected function serverGroup(): ServerGroup
    {
        return $this->app->make(ServerRegistry::class)->current();
    }
}
