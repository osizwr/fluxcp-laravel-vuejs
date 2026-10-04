<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\ServerRegistry;
use Generator;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;

/**
 * Importing item descriptions from the game client's `itemInfo.lua`.
 *
 * Ports modules/item/iteminfo.php. An operator uploads the same file their
 * client reads, and the descriptions players see in game appear on the panel's
 * item pages. They are stored in `cp_itemdesc`, which is the table FluxCP
 * created, so a panel migrated from FluxCP already has its descriptions and
 * only needs the item page to read them.
 *
 * ---------------------------------------------------------------------------
 * The file
 * ---------------------------------------------------------------------------
 *
 * itemInfo.lua is a Lua table keyed by item id. Only one field matters here:
 *
 *     [501] = {
 *         identifiedDisplayName = "Red Potion",
 *         identifiedDescriptionName = {
 *             "A potion made from grinded Red Herb.",
 *             "Restores 45~65 HP.",
 *             "Weight: ^7777771^000000"
 *         },
 *     },
 *
 * The description is sometimes written on one line instead, and both forms
 * appear in the same file. `^RRGGBB` switches the text colour from there on
 * and `^000000` switches back, which is the client's own markup and the only
 * formatting the text carries.
 *
 * The file is read a line at a time rather than loaded: a full itemInfo.lua is
 * several megabytes, and the legacy read the whole thing into memory with
 * `fread(filesize())` and then exploded it, which is two copies of it.
 *
 * ---------------------------------------------------------------------------
 * Why the stored description is HTML, and why that is safe
 * ---------------------------------------------------------------------------
 *
 * The colour markup has to survive somehow, and the legacy stored `<font>`
 * tags. This stores a `<span>` with a colour, and the important part is how:
 * every character of the item's own text is escaped first, and the only markup
 * added afterwards is built by this class from six matched hex digits. Nothing
 * from the file reaches the output as markup, so a booby-trapped itemInfo.lua
 * cannot put a script tag on an item page. Decision D20 keeps authored content
 * in Markdown with HTML stripped; this is generated, not authored, which is
 * why it is the one place HTML is stored.
 */
final readonly class ItemDescriptionImporter
{
    /** Item ids outside this range are not item ids. */
    private const MAX_ITEM_ID = 2_147_483_647;

    /** Rows per INSERT, to keep one statement a sane size. */
    private const CHUNK = 500;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
    ) {}

    /**
     * Parse a file and replace the stored descriptions it covers.
     *
     * Items the file does not mention are left alone, so importing a partial
     * file adds to what is there rather than wiping the rest.
     *
     * @return array{items: int, skipped: int}
     */
    public function import(string $path, ?CharMapServer $server = null): array
    {
        $server ??= $this->servers->currentCharMapServer();
        $connection = $this->connections->connection($server->connectionName());
        $table = $this->table();

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $imported = 0;
        $skipped = 0;
        $batch = [];

        try {
            foreach ($this->parse($handle) as $itemId => $description) {
                if ($description === '') {
                    $skipped++;

                    continue;
                }

                $batch[] = ['itemid' => $itemId, 'itemdesc' => $description];
                $imported++;

                if (count($batch) >= self::CHUNK) {
                    $connection->table($table)->upsert($batch, ['itemid'], ['itemdesc']);
                    $batch = [];
                }
            }
        } finally {
            fclose($handle);
        }

        if ($batch !== []) {
            $connection->table($table)->upsert($batch, ['itemid'], ['itemdesc']);
        }

        return ['items' => $imported, 'skipped' => $skipped];
    }

    /**
     * How many descriptions are stored.
     *
     * The legacy showed this on the upload page so an operator could tell
     * whether the import had done anything.
     */
    public function count(?CharMapServer $server = null): int
    {
        $server ??= $this->servers->currentCharMapServer();

        return (int) $this->connections->connection($server->connectionName())
            ->table($this->table())
            ->count();
    }

    /**
     * One item's description, or null when it has none.
     */
    public function describe(int $itemId, ?CharMapServer $server = null): ?string
    {
        $server ??= $this->servers->currentCharMapServer();

        $value = $this->connections->connection($server->connectionName())
            ->table($this->table())
            ->where('itemid', $itemId)
            ->value('itemdesc');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Remove every stored description.
     */
    public function clear(?CharMapServer $server = null): int
    {
        $server ??= $this->servers->currentCharMapServer();

        return $this->connections->connection($server->connectionName())
            ->table($this->table())
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * Walk the file, yielding item id => description HTML.
     *
     * A line is processed in stages rather than classified once, because the
     * two forms can share a line: real files put `[501] = {` on its own line,
     * but nothing in the format requires it, and a parser that consumed the
     * whole line on seeing the header would drop a description written beside
     * it.
     *
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function parse($handle): Generator
    {
        $itemId = null;
        $collecting = false;
        $lines = [];

        while (($raw = fgets($handle)) !== false) {
            $line = $raw;

            /*
             * A new item begins. Anything half-collected belongs to the
             * previous one and is emitted now -- a truncated file ends mid
             * block, and the descriptions before that point are still good.
             */
            if (preg_match('/\[\s*(\d+)\s*\]\s*=\s*\{/', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
                if ($itemId !== null && $collecting && $lines !== []) {
                    yield $itemId => $this->toHtml($lines);
                }

                $candidate = (int) $match[1][0];
                $itemId = $candidate > 0 && $candidate <= self::MAX_ITEM_ID ? $candidate : null;
                $collecting = false;
                $lines = [];

                // Carry on with whatever followed the header on this line.
                $line = substr($line, $match[0][1] + strlen($match[0][0]));
            }

            if ($itemId === null) {
                continue;
            }

            if ($collecting) {
                /*
                 * The block ends at the first closing brace. Anything before
                 * it on that line is still part of the description.
                 */
                if (($brace = strpos($line, '}')) !== false) {
                    foreach ($this->quotedStrings(substr($line, 0, $brace)) as $text) {
                        $lines[] = $text;
                    }

                    if ($lines !== []) {
                        yield $itemId => $this->toHtml($lines);
                    }

                    $collecting = false;
                    $lines = [];

                    continue;
                }

                foreach ($this->quotedStrings($line) as $text) {
                    $lines[] = $text;
                }

                continue;
            }

            /*
             * The lookbehind is the whole point: `unidentifiedDescriptionName`
             * ends with `identifiedDescriptionName`, so a plain match reads
             * the unidentified text -- "Unknown item, can be identified by a
             * Magnifier" -- as the item's description. Both fields are in
             * every block, and the unidentified one comes first.
             */
            if (preg_match('/(?<!un)identifiedDescriptionName\s*=\s*\{(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $rest = $match[1];

            /*
             * The one-line form closes on the same line. Checked before
             * entering the block, or a single-line description would be read
             * as the start of a multi-line one and swallow the fields after
             * it.
             */
            if (($brace = strpos($rest, '}')) !== false) {
                $inline = $this->quotedStrings(substr($rest, 0, $brace));

                if ($inline !== []) {
                    yield $itemId => $this->toHtml($inline);
                }

                continue;
            }

            $collecting = true;
            $lines = $this->quotedStrings($rest);
        }

        // A file that ends inside a block still yields what it had.
        if ($itemId !== null && $collecting && $lines !== []) {
            yield $itemId => $this->toHtml($lines);
        }
    }

    /**
     * Every double-quoted string on a line, in order, unescaped.
     *
     * @return list<string>
     */
    private function quotedStrings(string $line): array
    {
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $line, $matches) === 0) {
            return [];
        }

        return array_map(
            static fn (string $value): string => stripcslashes($value),
            $matches[1],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    */

    /**
     * Turn the collected lines into the stored HTML.
     *
     * @param  list<string>  $lines
     */
    private function toHtml(array $lines): string
    {
        $rendered = array_map(
            fn (string $line): string => $this->colourise(
                htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            $lines,
        );

        $rendered = array_filter($rendered, static fn (string $line): bool => trim($line) !== '');

        return implode('<br />', $rendered);
    }

    /**
     * Replace the client's `^RRGGBB` codes with coloured spans.
     *
     * The text arrives escaped, and only the six hex digits the pattern
     * matched are interpolated, so no part of the file can become markup.
     * `^000000` is the client's "back to normal" code rather than a request
     * for black, so it closes the span instead of opening a new one.
     */
    private function colourise(string $escaped): string
    {
        $parts = preg_split(
            '/\^([0-9A-Fa-f]{6})/',
            $escaped,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false || count($parts) === 1) {
            return $escaped;
        }

        $out = $parts[0];
        $open = false;

        for ($index = 1; $index < count($parts); $index += 2) {
            $colour = strtolower($parts[$index]);
            $text = $parts[$index + 1] ?? '';

            if ($open) {
                $out .= '</span>';
                $open = false;
            }

            if ($colour !== '000000') {
                $out .= '<span style="color:#'.$colour.'">';
                $open = true;
            }

            $out .= $text;
        }

        return $open ? $out.'</span>' : $out;
    }

    /**
     * The panel-owned table the descriptions live in, as PanelSchema declares
     * it and as FluxCP named it.
     */
    private function table(): string
    {
        return 'cp_itemdesc';
    }
}
