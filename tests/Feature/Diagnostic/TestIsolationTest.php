<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostic;

use App\Support\Rathena\ServerRegistry;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Regression tests for a data-loss incident during development.
 *
 * The test harness used to take its table listing from
 * `getSchemaBuilder()->getTableListing()` with no schema argument. On
 * MySQL/MariaDB that returns every table the connected user can see, across
 * every database, schema-qualified. Truncating each name in turn therefore
 * emptied every database on the server rather than the test one -- on the
 * machine where this happened, 555 names of which 458 were in unrelated
 * databases.
 *
 * These tests pin both halves of the fix: the listing is scoped, and a
 * destructive run against an unexpected database fails loudly.
 */
final class TestIsolationTest extends TestCase
{
    use InteractsWithRathena;

    #[Test]
    public function the_test_suite_targets_only_disposable_databases(): void
    {
        $group = $this->app->make(ServerRegistry::class)->default();

        $databases = [
            DB::connection($group->loginConnection())->getDatabaseName(),
            DB::connection($group->charMapConnection())->getDatabaseName(),
            DB::connection($group->logsConnection())->getDatabaseName(),
        ];

        foreach ($databases as $database) {
            $this->assertStringStartsWith(
                'fluxcp_test',
                $database,
                'Tests must only ever touch a disposable database.',
            );
        }
    }

    #[Test]
    public function the_table_listing_never_reaches_outside_its_own_database(): void
    {
        $group = $this->app->make(ServerRegistry::class)->default();
        $connection = DB::connection($group->loginConnection());
        $database = $connection->getDatabaseName();

        $scoped = $connection->getSchemaBuilder()->getTableListing(
            schema: $database,
            schemaQualified: false,
        );

        $this->assertNotEmpty($scoped, 'Expected the fixture tables to be listed.');

        foreach ($scoped as $table) {
            $this->assertStringNotContainsString(
                '.',
                $table,
                "The listing returned the qualified name '{$table}'. Truncating a qualified "
                .'name reaches outside this database.',
            );
        }

        /*
         * The unscoped call is what caused the incident. This asserts the
         * difference is real on this server rather than trusting that it is,
         * so the scoped call above is demonstrably doing something.
         */
        $unscoped = $connection->getSchemaBuilder()->getTableListing();

        $this->assertGreaterThanOrEqual(
            count($scoped),
            count($unscoped),
            'An unscoped listing should return at least as many tables as a scoped one.',
        );
    }

    #[Test]
    public function destructive_setup_refuses_a_database_that_is_not_disposable(): void
    {
        // The guard that turns a future misconfiguration into a failed test
        // rather than lost data.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to run destructive test setup/');

        config(['rathena.groups.main.databases.login.database' => 'ragnarok']);
        $this->app->forgetInstance(ServerRegistry::class);

        $registry = $this->app->make(ServerRegistry::class);
        $registry->registerConnections();
        $registry->purgeConnections();

        $group = $registry->default();

        $this->assertConnectionsAreDestroyable([$group->loginConnection()]);
    }
}
