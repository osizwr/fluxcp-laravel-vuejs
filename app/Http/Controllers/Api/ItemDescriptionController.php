<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Rathena\ItemDescriptionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Importing item descriptions from the client's `itemInfo.lua`.
 *
 * Ports modules/item/iteminfo.php, which an earlier revision of this port
 * mistook for a filter-vocabulary endpoint and left unported.
 *
 * The legacy moved the upload into the web root as `itemInfo.lua`, parsed it
 * from there and deleted it afterwards -- so a failed parse left an uploaded
 * file inside the document root. This parses the upload where PHP already put
 * it and never writes it anywhere.
 */
final class ItemDescriptionController
{
    public function __construct(private readonly ItemDescriptionImporter $descriptions) {}

    /**
     * How many descriptions are stored.
     *
     * The legacy showed this beside the upload form, so an operator could tell
     * whether an import had taken.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => ['items' => $this->descriptions->count()]]);
    }

    /**
     * Parse an uploaded itemInfo.lua and store the descriptions in it.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $maximum = max(1, (int) config('panel.items.item_info_max_kilobytes', 16_384));

        $request->validate([
            /*
             * No mime check. itemInfo.lua has no registered type and arrives
             * as whatever the browser guesses -- text/plain, application/octet
             * -stream or nothing -- so a mime rule rejects valid uploads while
             * proving nothing about the contents. What makes this safe is that
             * the file is parsed, never executed, and never written anywhere.
             */
            'file' => ['required', 'file', "max:{$maximum}"],
        ]);

        $file = $request->file('file');

        try {
            $summary = $this->descriptions->import($file->getRealPath());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        if ($summary['items'] === 0) {
            throw ValidationException::withMessages([
                'file' => 'No item descriptions were found in that file. '
                    .'itemInfo.lua is expected, with identifiedDescriptionName entries.',
            ]);
        }

        return response()->json([
            'message' => 'Imported '.$summary['items'].' item descriptions.',
            'data' => [
                'imported' => $summary['items'],
                'skipped' => $summary['skipped'],
                'items' => $this->descriptions->count(),
            ],
        ]);
    }

    /**
     * Remove every stored description.
     *
     * Not in the legacy, which could only ever add. Without it an operator who
     * imported the wrong file has no way back short of SQL.
     */
    public function destroy(): JsonResponse
    {
        $removed = $this->descriptions->clear();

        return response()->json([
            'message' => $removed === 0
                ? 'There were no stored descriptions.'
                : 'Removed '.$removed.' stored descriptions.',
            'data' => ['removed' => $removed],
        ]);
    }
}
