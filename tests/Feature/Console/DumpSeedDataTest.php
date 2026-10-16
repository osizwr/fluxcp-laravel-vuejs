<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Writing a table's rows out as a seeder.
 *
 * The test that matters is the round trip: dump a table, empty it, run what
 * was written, and find the rows back. A generated file that merely looks
 * right is not worth much -- it is only a seeder if it seeds.
 */
final class DumpSeedDataTest extends TestCase
{
    use RefreshDatabase;

    private string $written;

    protected function setUp(): void
    {
        parent::setUp();

        $this->written = database_path('seeders/TestSnapshotSeeder.php');

        Schema::create('dump_fixture', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->integer('rank')->nullable();
        });
    }

    protected function tearDown(): void
    {
        File::delete($this->written);

        parent::tearDown();
    }

    private function seedFixture(): void
    {
        DB::table('dump_fixture')->insert([
            ['id' => 1, 'title' => 'First', 'body' => 'Hello', 'rank' => 3],
            ['id' => 2, 'title' => "It's quoted", 'body' => null, 'rank' => null],
            ['id' => 3, 'title' => 'Back\\slash', 'body' => "two\nlines", 'rank' => 0],
            // The ones a generated PHP file gets wrong: a dollar that would
            // read as a variable, a tab, and a carriage return.
            ['id' => 4, 'title' => 'Costs $5 {$body}', 'body' => "tab\there\r\nand CR", 'rank' => 9],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The round trip
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function what_it_writes_puts_the_rows_back(): void
    {
        $this->seedFixture();

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture',
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFileExists($this->written);

        // Gone, as it would be on the machine this is being restored onto.
        DB::table('dump_fixture')->delete();
        $this->assertSame(0, DB::table('dump_fixture')->count());

        $this->runWrittenSeeder();

        $rows = DB::table('dump_fixture')->orderBy('id')->get();

        $this->assertCount(4, $rows);
        $this->assertSame('First', $rows[0]->title);
        // The awkward ones: a quote, a backslash, a newline, and nulls.
        $this->assertSame("It's quoted", $rows[1]->title);
        $this->assertNull($rows[1]->body);
        $this->assertSame('Back\\slash', $rows[2]->title);
        $this->assertSame("two\nlines", $rows[2]->body);
        $this->assertSame(0, (int) $rows[2]->rank);
        $this->assertSame('Costs $5 {$body}', $rows[3]->title);
        $this->assertSame("tab\there\r\nand CR", $rows[3]->body);
    }

    /**
     * Running it twice is not two copies.
     */
    #[Test]
    public function the_written_seeder_can_be_run_again(): void
    {
        $this->seedFixture();

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture',
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        $this->runWrittenSeeder();
        $this->runWrittenSeeder();

        $this->assertSame(4, DB::table('dump_fixture')->count());
    }

    /**
     * An edit made since the dump is replaced, not duplicated.
     */
    #[Test]
    public function it_upserts_over_a_changed_row(): void
    {
        $this->seedFixture();

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture',
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        DB::table('dump_fixture')->where('id', 1)->update(['title' => 'Changed since']);

        $this->runWrittenSeeder();

        $this->assertSame(4, DB::table('dump_fixture')->count());
        $this->assertSame('First', DB::table('dump_fixture')->where('id', 1)->value('title'));
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    /**
     * The whole reason the command has an opinion.
     *
     * rAthena keeps `login.user_pass` in cleartext, and the file this writes
     * is tracked by git.
     */
    #[Test]
    public function it_refuses_a_table_holding_a_password(): void
    {
        Schema::create('dump_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('userid');
            $table->string('user_pass');
        });

        DB::table('dump_secrets')->insert(['userid' => 'someone', 'user_pass' => 'hunter2']);

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_secrets',
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertFailed();

        $this->assertFileDoesNotExist($this->written);
    }

    #[Test]
    public function it_dumps_a_refused_table_when_told_to_anyway(): void
    {
        Schema::create('dump_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('user_pass');
        });

        DB::table('dump_secrets')->insert(['user_pass' => 'hunter2']);

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_secrets',
            '--class' => 'TestSnapshotSeeder',
            '--include-sensitive' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertStringContainsString('hunter2', (string) File::get($this->written));
    }

    #[Test]
    public function it_will_not_overwrite_without_force(): void
    {
        $this->seedFixture();

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture',
            '--class' => 'TestSnapshotSeeder',
        ])->assertSuccessful();

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture',
            '--class' => 'TestSnapshotSeeder',
        ])->assertFailed();
    }

    #[Test]
    public function it_needs_a_table(): void
    {
        $this->artisan('panel:dump-seed', ['--class' => 'TestSnapshotSeeder'])->assertFailed();

        $this->assertFileDoesNotExist($this->written);
    }

    #[Test]
    public function a_table_that_is_not_there_is_skipped_rather_than_fatal(): void
    {
        $this->seedFixture();

        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture,not_a_table_here',
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        $written = (string) File::get($this->written);

        $this->assertStringContainsString('no rows', $written);
        $this->assertStringContainsString('First', $written);
    }

    /**
     * A name that is not a table name never reaches the database.
     */
    #[Test]
    public function it_refuses_a_table_name_that_is_not_one(): void
    {
        $this->artisan('panel:dump-seed', [
            '--tables' => 'dump_fixture; DROP TABLE dump_fixture',
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue(Schema::hasTable('dump_fixture'));
    }

    /*
    |--------------------------------------------------------------------------
    | --all
    |--------------------------------------------------------------------------
    */

    /**
     * Everything with rows in it, and nothing that would do harm.
     *
     * `migrations` is the one that matters: seeding it tells Laravel that
     * migrations have run which have not, and the next `migrate` then skips
     * the tables it was supposed to create.
     */
    #[Test]
    public function all_takes_every_table_but_the_ones_that_never_travel(): void
    {
        $this->seedFixture();

        $this->artisan('panel:dump-seed', [
            '--all' => true,
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        $written = (string) File::get($this->written);

        $this->assertStringContainsString("table('dump_fixture')", $written);

        foreach (['migrations', 'sessions', 'cache', 'jobs', 'failed_jobs'] as $never) {
            $this->assertStringNotContainsString("table('{$never}')", $written);
        }
    }

    /**
     * A table holding credentials is left out of a bulk run rather than
     * ending it: one awkward table in a hundred must not make --all useless.
     */
    #[Test]
    public function all_skips_a_table_with_secrets_instead_of_failing(): void
    {
        $this->seedFixture();

        Schema::create('dump_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('user_pass');
        });

        DB::table('dump_secrets')->insert(['user_pass' => 'hunter2']);

        $this->artisan('panel:dump-seed', [
            '--all' => true,
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        $written = (string) File::get($this->written);

        $this->assertStringNotContainsString('hunter2', $written);
        $this->assertStringNotContainsString("table('dump_secrets')", $written);
        // ...while the rest of the run still happened.
        $this->assertStringContainsString("table('dump_fixture')", $written);
    }

    /**
     * An empty table earns no line in a bulk run.
     *
     * Most of a real database is empty, and a file of "no rows" comments
     * buries the handful of tables that say something.
     */
    #[Test]
    public function all_leaves_out_tables_with_nothing_in_them(): void
    {
        $this->seedFixture();

        Schema::create('dump_empty', function (Blueprint $table): void {
            $table->id();
            $table->string('nothing')->nullable();
        });

        $this->artisan('panel:dump-seed', [
            '--all' => true,
            '--class' => 'TestSnapshotSeeder',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertStringNotContainsString('dump_empty', (string) File::get($this->written));
    }

    /*
    |--------------------------------------------------------------------------
    | Running the file it wrote
    |--------------------------------------------------------------------------
    */

    /**
     * Load the generated class and run it, as `db:seed` would.
     *
     * Required once and then reused: a class cannot be declared twice in one
     * process, and the second call in a test is the point of the exercise.
     */
    private function runWrittenSeeder(): void
    {
        $class = 'Database\\Seeders\\TestSnapshotSeeder';

        if (! class_exists($class, false)) {
            require $this->written;
        }

        /** @var Seeder $seeder */
        $seeder = new $class;

        $seeder->setContainer($this->app)->__invoke();
    }
}
