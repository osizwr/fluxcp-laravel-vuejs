<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Shop\ItemShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Managing what the credit shop sells.
 *
 * Ports modules/itemshop/add.php, edit.php, delete.php and imagedel.php.
 *
 * Each action needs its own ability on top of the route level, as the legacy
 * access map had them: AddShopItem, EditShopItem, DeleteShopItem.
 */
final class ItemShopAdminController
{
    public function __construct(private readonly ItemShop $shop) {}

    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorise($request, 'AddShopItem');

        $validated = $this->validateItem($request);

        $id = $this->shop->addShopItem(
            nameId: $validated['nameid'],
            cost: $validated['cost'],
            quantity: $validated['quantity'],
            category: $validated['category'] ?? null,
            info: $validated['info'] ?? null,
            useExisting: (bool) ($validated['use_existing'] ?? false),
        );

        return response()->json([
            'message' => 'The item is now on sale.',
            'data' => ['id' => $id],
        ], 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, int $item): JsonResponse
    {
        $this->authorise($request, 'EditShopItem');

        $validated = $this->validateItem($request);

        $changed = $this->shop->updateShopItem($item, [
            'nameid' => $validated['nameid'],
            'cost' => $validated['cost'],
            'quantity' => $validated['quantity'],
            'category' => $validated['category'] ?? null,
            'info' => $validated['info'] ?? null,
            'use_existing' => (bool) ($validated['use_existing'] ?? false) ? 1 : 0,
        ]);

        abort_unless($changed, 404, 'No such shop item.');

        return response()->json(['message' => 'The shop item has been saved.']);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->authorise($request, 'DeleteShopItem');

        abort_unless($this->shop->removeShopItem($item), 404, 'No such shop item.');

        /*
         * The cp_redeemlog rows are left alone. They are what somebody has
         * already bought, and deleting them would take away a purchase that
         * has not been collected yet.
         */
        return response()->json(['message' => 'The item has been withdrawn from sale.']);
    }

    /**
     * Remove a shop item's image.
     *
     * Ports modules/itemshop/imagedel.php. Images live under the configured
     * public disk, named after the item id -- the legacy stored them on disk
     * too rather than in the database.
     */
    public function destroyImage(Request $request, int $item): JsonResponse
    {
        $this->authorise($request, 'EditShopItem');

        $disk = Storage::disk(
            (string) config('panel.item_shop.image_disk', 'public'),
        );

        $directory = trim((string) config('panel.item_shop.image_directory', 'shop'), '/');
        $removed = 0;

        foreach ((array) config('panel.item_shop.image_extensions', []) as $extension) {
            $path = "{$directory}/{$item}.{$extension}";

            if ($disk->exists($path)) {
                $disk->delete($path);
                $removed++;
            }
        }

        return response()->json([
            'message' => $removed > 0
                ? 'The image has been removed.'
                : 'That item has no image.',
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateItem(Request $request): array
    {
        return $request->validate([
            'nameid' => ['required', 'integer', 'min:1'],
            /*
             * The caps are the operator's, from config. An item priced at zero
             * is free rather than broken, so the minimum is zero -- some
             * servers list a free starter pack.
             */
            'cost' => ['required', 'integer', 'min:0', 'max:'.(int) config('panel.item_shop.max_cost', 99999)],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.(int) config('panel.item_shop.max_quantity', 99)],
            'category' => ['nullable', 'integer'],
            'info' => ['nullable', 'string', 'max:65535'],
            'use_existing' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorise(Request $request, string $ability): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);
        abort_unless($account->can($ability), 403, 'You may not change the shop.');

        return $account;
    }
}
