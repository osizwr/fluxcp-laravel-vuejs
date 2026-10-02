<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Support\Rathena\Schema\PanelSchema;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The panel-owned schema installer.
 *
 * The property that matters most here is that it is additive: an operator
 * pointing this at a live server's database, which may already carry a FluxCP
 * schema, must not have anything dropped or rewritten.
 */
final class PanelSchemaInstallTest extends TestCase
{
    use InteractsWithRathena;

    #[Test]
    public function it_declares_all_twenty_five_panel_owned_tables(): void
    {
        $this->assertCount(18, PanelSchema::loginTables());
        $this->assertCount(7, PanelSchema::charMapTables());
        $this->assertCount(25, PanelSchema::allTableNames());
    }

    #[Test]
    public function it_places_each_table_in_the_database_the_legacy_installer_used(): void
    {
        // Placement is not cosmetic: these tables are joined directly against
        // `login` and `char` in the same schema, so moving one turns those
        // joins into cross-database joins. See D4.
        $this->assertArrayHasKey('cp_credits', PanelSchema::loginTables());
        $this->assertArrayHasKey('cp_createlog', PanelSchema::loginTables());
        $this->assertArrayHasKey('cp_banlog', PanelSchema::loginTables());

        $this->assertArrayHasKey('cp_charprefs', PanelSchema::charMapTables());
        $this->assertArrayHasKey('cp_itemshop', PanelSchema::charMapTables());
        $this->assertArrayHasKey('cp_redeemlog', PanelSchema::charMapTables());
    }

    #[Test]
    public function it_creates_every_table_on_a_fresh_database(): void
    {
        $group = $this->app->make(ServerRegistry::class)->default();

        // The test harness has already created a handful of these; drop them
        // so the command has real work to do.
        foreach (PanelSchema::loginTables() as $name => $_) {
            Schema::connection($group->loginConnection())->dropIfExists($name);
        }
        foreach (PanelSchema::charMapTables() as $name => $_) {
            Schema::connection($group->charMapConnection())->dropIfExists($name);
        }

        $this->artisan('panel:install-schema')->assertSuccessful();

        foreach (PanelSchema::loginTables() as $name => $_) {
            $this->assertTrue(
                Schema::connection($group->loginConnection())->hasTable($name),
                "Expected {$name} in the login database.",
            );
        }

        foreach (PanelSchema::charMapTables() as $name => $_) {
            $this->assertTrue(
                Schema::connection($group->charMapConnection())->hasTable($name),
                "Expected {$name} in the char/map database.",
            );
        }
    }

    #[Test]
    public function it_can_be_run_twice_without_error(): void
    {
        $this->artisan('panel:install-schema')->assertSuccessful();
        $this->artisan('panel:install-schema')->assertSuccessful();
    }

    #[Test]
    public function it_leaves_an_existing_table_and_its_data_alone(): void
    {
        // The guarantee that makes this safe to point at a live database.
        $group = $this->app->make(ServerRegistry::class)->default();
        $connection = $group->loginConnection();

        $this->artisan('panel:install-schema')->assertSuccessful();

        Schema::connection($connection)->table('cp_credits', function ($table): void {
            $table->string('operator_note', 64)->nullable();
        });

        DB::connection($connection)->table('cp_credits')->insert([
            'account_id' => 2000001,
            'balance' => 4200,
            'operator_note' => 'added by the operator',
        ]);

        $this->artisan('panel:install-schema')->assertSuccessful();

        $row = DB::connection($connection)
            ->table('cp_credits')
            ->where('account_id', 2000001)
            ->first();

        $this->assertNotNull($row, 'Reinstalling must not drop existing rows.');
        $this->assertSame(4200, (int) $row->balance);
        $this->assertSame('added by the operator', $row->operator_note);
        $this->assertTrue(
            Schema::connection($connection)->hasColumn('cp_credits', 'operator_note'),
            "Reinstalling must not drop an operator's own column.",
        );
    }

    #[Test]
    public function pretending_reports_without_creating(): void
    {
        $group = $this->app->make(ServerRegistry::class)->default();

        Schema::connection($group->loginConnection())->dropIfExists('cp_trusted');

        $this->artisan('panel:install-schema', ['--pretend' => true])->assertSuccessful();

        $this->assertFalse(
            Schema::connection($group->loginConnection())->hasTable('cp_trusted'),
            'A pretend run must not create anything.',
        );
    }

    #[Test]
    public function it_fails_cleanly_for_an_unknown_server_group(): void
    {
        $this->artisan('panel:install-schema', ['--group' => 'no-such-group'])->assertFailed();
    }
}
