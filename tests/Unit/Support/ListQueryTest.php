<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Http\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paging and sorting for listing endpoints.
 */
final class ListQueryTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $query
     */
    private function request(array $query = []): Request
    {
        return Request::create('/api/things', 'GET', $query);
    }

    private function listQuery(): ListQuery
    {
        return new ListQuery(
            sortable: ['name' => 'char.name', 'level' => 'char.base_level'],
            defaultSort: 'level',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Page size
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_default_page_size_comes_from_config(): void
    {
        config()->set('panel.pagination.per_page', 25);

        $this->assertSame(25, $this->listQuery()->perPage($this->request()));
    }

    #[Test]
    public function a_requested_page_size_is_honoured(): void
    {
        $this->assertSame(5, $this->listQuery()->perPage($this->request(['per_page' => 5])));
    }

    #[Test]
    public function the_page_size_is_capped(): void
    {
        /*
         * per_page is attacker-controlled. Uncapped, one request can ask for
         * every row in a log table with millions of them.
         */
        config()->set('panel.pagination.max_per_page', 100);

        $this->assertSame(100, $this->listQuery()->perPage($this->request(['per_page' => 100000])));
    }

    #[Test]
    public function a_nonsensical_page_size_falls_back_to_at_least_one(): void
    {
        foreach ([0, -5, 'abc'] as $value) {
            $this->assertGreaterThanOrEqual(
                1,
                $this->listQuery()->perPage($this->request(['per_page' => $value])),
                "per_page={$value} must not produce a non-positive limit.",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Sorting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_default_sort_applies_when_none_is_requested(): void
    {
        [$key, $direction] = $this->listQuery()->resolveSort($this->request());

        $this->assertSame('level', $key);
        $this->assertSame('desc', $direction);
    }

    #[Test]
    public function an_allowed_sort_is_accepted(): void
    {
        [$key, $direction] = $this->listQuery()->resolveSort(
            $this->request(['sort' => 'name', 'direction' => 'asc']),
        );

        $this->assertSame('name', $key);
        $this->assertSame('asc', $direction);
    }

    #[Test]
    public function a_column_outside_the_allow_list_is_refused(): void
    {
        /*
         * The point of the allow-list. The value reaches SQL, so only names
         * the endpoint declared are accepted — including names that are real
         * columns but were not offered.
         */
        $this->expectException(ValidationException::class);

        $this->listQuery()->resolveSort($this->request(['sort' => 'char.user_pass']));
    }

    #[Test]
    public function the_refusal_names_the_sorts_that_do_exist(): void
    {
        try {
            $this->listQuery()->resolveSort($this->request(['sort' => 'nonsense']));
            $this->fail('An unknown sort should be refused.');
        } catch (ValidationException $e) {
            $message = $e->errors()['sort'][0];

            $this->assertStringContainsString('name', $message);
            $this->assertStringContainsString('level', $message);
        }
    }

    #[Test]
    public function an_unknown_sort_is_refused_rather_than_silently_defaulted(): void
    {
        /*
         * The distinction this test exists for: the result must not come back
         * as the default sort with no complaint. Falling back silently makes a
         * typo in a link look like a data problem rather than a bad request,
         * and hides a renamed column from whoever renamed it.
         */
        $thrown = null;

        try {
            $resolved = $this->listQuery()->resolveSort($this->request(['sort' => 'levle']));
        } catch (ValidationException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A misspelt sort silently returned '.json_encode($resolved ?? null));
        $this->assertArrayHasKey('sort', $thrown->errors());
    }

    #[Test]
    public function an_invalid_direction_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->listQuery()->resolveSort($this->request(['sort' => 'name', 'direction' => 'sideways']));
    }

    #[Test]
    public function a_direction_is_not_case_sensitive(): void
    {
        [, $direction] = $this->listQuery()->resolveSort(
            $this->request(['sort' => 'name', 'direction' => 'DESC']),
        );

        $this->assertSame('desc', $direction);
    }

    #[Test]
    public function a_listing_with_no_sortable_columns_refuses_any_sort(): void
    {
        $this->expectException(ValidationException::class);

        (new ListQuery)->resolveSort($this->request(['sort' => 'anything']));
    }

    #[Test]
    public function no_sort_is_applied_when_there_is_no_default_and_none_requested(): void
    {
        [$key] = (new ListQuery(sortable: ['name' => 'char.name']))->resolveSort($this->request());

        $this->assertNull($key);
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata for the client
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_metadata_describes_the_available_and_applied_sort(): void
    {
        $meta = $this->listQuery()->metadata($this->request(['sort' => 'name', 'direction' => 'asc']));

        $this->assertSame(['name', 'level'], $meta['sortable']);
        $this->assertSame('name', $meta['sort']);
        $this->assertSame('asc', $meta['direction']);
    }

    #[Test]
    public function the_metadata_never_exposes_the_underlying_columns(): void
    {
        /*
         * The client sees the public names only. That keeps the schema out of
         * the API and means renaming a column does not break a saved link.
         */
        $meta = $this->listQuery()->metadata($this->request());

        $this->assertSame(['name', 'level'], $meta['sortable']);
        $this->assertStringNotContainsString('char.', json_encode($meta));
    }
}
