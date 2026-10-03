<?php

declare(strict_types=1);

namespace Tests\Feature\Rathena;

use App\Services\Rathena\ReferenceTables;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The item and monster merge.
 *
 * This is the behaviour thirteen actions depend on, and the one where being
 * subtly wrong is invisible: a panel that reads only `item_db_re` looks
 * perfect on a fresh rAthena install and is wrong on every server that has
 * ever added or rebalanced an item. The tests therefore check the override
 * cases rather than that a query runs.
 */
final class ReferenceTableMergeTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The merge caches schema introspection; a test that changes the
        // schema must not read another test's answer.
        Cache::flush();
    }

    private function tables(): ReferenceTables
    {
        return $this->app->make(ReferenceTables::class);
    }

    private function charMapConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(string $table, int $id, array $attributes = []): void
    {
        DB::connection($this->charMapConnection())->table($table)->insert([
            'id' => $id,
            'name_aegis' => "Item{$id}",
            'name_english' => "Item {$id}",
            'type' => 'Etc',
            'price_buy' => 100,
            'slots' => 0,
            ...$attributes,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Merging
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function base_rows_are_returned_when_there_is_no_override(): void
    {
        $this->item('item_db_re', 501, ['name_english' => 'Red Potion']);
        $this->item('item_db_re', 502, ['name_english' => 'Orange Potion']);

        $rows = $this->tables()->items()->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Red Potion', $rows[0]->name_english);
    }

    #[Test]
    public function an_override_row_replaces_the_base_row(): void
    {
        /*
         * The whole point. A server that rebalances Red Potion puts its
         * version in item_db2_re, and the panel must show that one.
         */
        $this->item('item_db_re', 501, ['name_english' => 'Red Potion', 'price_buy' => 50]);
        $this->item('item_db2_re', 501, ['name_english' => 'Red Potion+', 'price_buy' => 5000]);

        $rows = $this->tables()->items()->get();

        $this->assertCount(1, $rows, 'A replaced item must appear once, not twice.');
        $this->assertSame('Red Potion+', $rows[0]->name_english);
        $this->assertSame(5000, (int) $rows[0]->price_buy);
    }

    #[Test]
    public function an_override_only_row_is_added(): void
    {
        $this->item('item_db_re', 501, ['name_english' => 'Red Potion']);
        $this->item('item_db2_re', 30001, ['name_english' => 'Custom Blade']);

        $rows = $this->tables()->items()->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Custom Blade', $rows[1]->name_english);
    }

    #[Test]
    public function base_rows_without_an_override_survive_alongside_overridden_ones(): void
    {
        $this->item('item_db_re', 501, ['name_english' => 'Red Potion']);
        $this->item('item_db_re', 502, ['name_english' => 'Orange Potion']);
        $this->item('item_db_re', 503, ['name_english' => 'Yellow Potion']);
        $this->item('item_db2_re', 502, ['name_english' => 'Orange Potion+']);

        $rows = $this->tables()->items()->orderBy('id')->get()->pluck('name_english')->all();

        $this->assertSame(['Red Potion', 'Orange Potion+', 'Yellow Potion'], $rows);
    }

    #[Test]
    public function the_origin_table_is_reported(): void
    {
        // The legacy item listing filtered on this to separate stock entries
        // from custom ones, so it has to survive the port.
        $this->item('item_db_re', 501);
        $this->item('item_db2_re', 30001);

        $rows = $this->tables()->items()->orderBy('id')->get();

        $this->assertSame('item_db_re', $rows[0]->origin_table);
        $this->assertSame('item_db2_re', $rows[1]->origin_table);
    }

    #[Test]
    public function an_overridden_row_reports_the_override_as_its_origin(): void
    {
        $this->item('item_db_re', 501);
        $this->item('item_db2_re', 501);

        $this->assertSame('item_db2_re', $this->tables()->items()->first()->origin_table);
    }

    /*
    |--------------------------------------------------------------------------
    | Composability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_result_can_be_filtered_sorted_and_paginated_in_sql(): void
    {
        /*
         * What the temporary table could not do. FluxCP copied both tables in
         * full on every request and sliced the result in PHP.
         */
        foreach (range(1, 25) as $n) {
            $this->item('item_db_re', 500 + $n, [
                'name_english' => sprintf('Potion %02d', $n),
                'price_buy' => $n * 10,
            ]);
        }

        $this->item('item_db2_re', 510, ['name_english' => 'Potion 10 Custom', 'price_buy' => 9999]);

        $page = $this->tables()->items()
            ->where('price_buy', '>=', 100)
            ->orderByDesc('price_buy')
            ->paginate(5);

        $this->assertSame(5, $page->count());
        $this->assertSame('Potion 10 Custom', $page->items()[0]->name_english);
        $this->assertGreaterThan(5, $page->total());
    }

    #[Test]
    public function counting_the_merged_result_does_not_double_count(): void
    {
        $this->item('item_db_re', 501);
        $this->item('item_db_re', 502);
        $this->item('item_db2_re', 501);

        $this->assertSame(2, $this->tables()->items()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Schema handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_missing_override_table_is_not_an_error(): void
    {
        /*
         * The normal state of a server with no custom entries. FluxCP's
         * temporary table raised here; the base table alone is the right
         * answer and the item pages must keep working.
         *
         * Pointed at a name that does not exist rather than dropping the real
         * table: the schema is built once for the class, so a dropped table
         * would be missing for every test that follows.
         */
        config(['rathena.reference_tables.items.renewal.override' => 'item_db2_absent']);
        Cache::flush();

        $this->item('item_db_re', 501, ['name_english' => 'Red Potion']);

        $rows = $this->tables()->items()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Red Potion', $rows[0]->name_english);
        $this->assertSame('item_db_re', $rows[0]->origin_table);
    }

    #[Test]
    public function a_null_override_is_not_an_error(): void
    {
        // An operator who has deliberately turned the override off.
        config(['rathena.reference_tables.items.renewal.override' => null]);
        Cache::flush();

        $this->item('item_db_re', 501, ['name_english' => 'Red Potion']);

        $this->assertCount(1, $this->tables()->items()->get());
    }

    #[Test]
    public function a_missing_base_table_names_the_renewal_setting(): void
    {
        config(['rathena.reference_tables.items.renewal.base' => 'item_db_absent']);
        Cache::flush();

        $this->expectException(RuntimeException::class);
        // The actual cause is almost always a renewal flag that does not match
        // the server, so the message has to say so.
        $this->expectExceptionMessageMatches('/renewal/i');

        $this->tables()->items()->get();
    }

    #[Test]
    public function an_override_missing_a_column_is_refused_by_name(): void
    {
        $connection = $this->charMapConnection();

        // A table of its own, so nothing else in the class sees the change.
        Schema::connection($connection)->create('item_db2_partial', function ($table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name_aegis', 50)->default('');
        });

        config(['rathena.reference_tables.items.renewal.override' => 'item_db2_partial']);
        Cache::flush();

        try {
            $this->tables()->items()->get();
            $this->fail('An override missing columns should be refused.');
        } catch (RuntimeException $e) {
            // Names the column, so the operator knows what to add rather than
            // seeing a driver error about column counts.
            $this->assertStringContainsString('script', $e->getMessage());
            $this->assertStringContainsString('item_db2_partial', $e->getMessage());
        } finally {
            Schema::connection($connection)->drop('item_db2_partial');
        }
    }

    #[Test]
    public function the_key_column_is_detected_rather_than_assumed(): void
    {
        /*
         * rAthena has shipped both spellings. Assuming one makes the merge
         * match nothing on the version that uses the other, which returns
         * every row twice rather than failing.
         */
        $this->assertSame('id', $this->tables()->keyColumn('items'));
        $this->assertSame('ID', $this->tables()->keyColumn('monsters'));
    }

    #[Test]
    public function monsters_merge_on_their_own_key_spelling(): void
    {
        $connection = $this->charMapConnection();

        DB::connection($connection)->table('mob_db_re')->insert([
            'ID' => 1002, 'Sprite' => 'PORING', 'kName' => 'Poring', 'iName' => 'Poring', 'LV' => 1, 'HP' => 50,
        ]);
        DB::connection($connection)->table('mob_db2_re')->insert([
            'ID' => 1002, 'Sprite' => 'PORING', 'kName' => 'Poring', 'iName' => 'Angry Poring', 'LV' => 99, 'HP' => 9999,
        ]);

        $rows = $this->tables()->monsters()->get();

        $this->assertCount(1, $rows, 'The capitalised key must still match.');
        $this->assertSame('Angry Poring', $rows[0]->iName);
        $this->assertSame(99, (int) $rows[0]->LV);
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_pre_renewal_world_reads_the_pre_renewal_tables(): void
    {
        config(['rathena.groups.main.char_map_servers.main.renewal' => false]);
        $this->app->forgetInstance(ServerRegistry::class);

        $this->assertSame(
            ['base' => 'item_db', 'override' => 'item_db2', 'alias' => 'items'],
            $this->tables()->tablesFor('items'),
        );
    }

    #[Test]
    public function a_renewal_world_reads_the_renewal_tables(): void
    {
        $this->assertSame(
            ['base' => 'item_db_re', 'override' => 'item_db2_re', 'alias' => 'items'],
            $this->tables()->tablesFor('items'),
        );
    }

    #[Test]
    public function an_unknown_kind_is_refused_with_the_valid_ones_listed(): void
    {
        $this->expectExceptionMessageMatches('/items, monsters/');

        $this->tables()->tablesFor('weapons');
    }
}
