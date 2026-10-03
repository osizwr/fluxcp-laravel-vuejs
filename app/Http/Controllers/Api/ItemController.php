<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\ItemResource;
use App\Services\Rathena\ItemService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The item database.
 *
 * Ports modules/item/index.php, view.php and iteminfo.php. Every read goes
 * through ReferenceTables, so the server's own `item_db2` entries are included
 * and a custom item shows its custom stats (D6).
 */
final class ItemController
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Search the item database.
     *
     * @throws ValidationException
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $this->validateFilters($request);

        $list = new ListQuery(
            sortable: ItemService::sortableColumns(),
            defaultSort: 'id',
            defaultDirection: 'asc',
        );

        try {
            $query = $this->items->search($filters);
        } catch (InvalidArgumentException $e) {
            /*
             * The service refuses an unknown type, location or operator by
             * throwing. Reported as a validation error so the client shows it
             * against the field rather than as a server fault.
             */
            throw ValidationException::withMessages(['filter' => $e->getMessage()]);
        }

        return ItemResource::collection($list->paginate($query, $request))
            ->additional(['meta' => $list->metadata($request)]);
    }

    /**
     * One item.
     */
    public function show(Request $request, int $item): JsonResponse
    {
        $row = $this->items->find($item);

        abort_if($row === null, 404, 'No such item.');

        return ItemResource::make($row)->response();
    }

    /**
     * The filter vocabulary, for building a search form.
     *
     * Published so the client does not carry its own copy of the item types
     * and equip locations, which would drift from the server's configuration
     * the first time an operator changed it.
     */
    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'types' => (object) config('rathena_reference.item_types', []),
                'locations' => (object) config('rathena_reference.equip_locations', []),
                'jobs' => (object) [
                    ...(array) config('rathena_reference.equip_jobs.base', []),
                    ...(array) config('rathena_reference.equip_jobs.renewal', []),
                ],
                'classes' => (object) [
                    ...(array) config('rathena_reference.equip_classes.base', []),
                    ...(array) config('rathena_reference.equip_classes.renewal', []),
                ],
                'operators' => ItemService::operators(),
                'comparable' => ItemService::comparableColumns(),
                'sortable' => array_keys(ItemService::sortableColumns()),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateFilters(Request $request): array
    {
        $comparable = ItemService::comparableColumns();

        $rules = [
            'id' => ['nullable', 'integer', 'min:0'],
            'name' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:20'],
            'subtype' => ['nullable', 'string', 'max:20'],
            'location' => ['nullable', 'string', 'max:40'],
            'job' => ['nullable', 'string', 'max:40'],
            'class' => ['nullable', 'string', 'max:40'],
            'refineable' => ['nullable', 'boolean'],
            'origin' => ['nullable', 'in:stock,custom'],
        ];

        foreach ($comparable as $column) {
            $rules[$column] = ['nullable', 'integer'];
            $rules["{$column}_op"] = ['nullable', 'in:'.implode(',', ItemService::operators())];
        }

        return $request->validate($rules);
    }
}
