<?php

declare(strict_types=1);

namespace App\Support\Content;

/**
 * The metadata block at the top of a Markdown file, and the body under it.
 *
 *     ---
 *     title: Creating an account
 *     order: 2
 *     ---
 *
 *     The body starts here.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a YAML parser
 * ---------------------------------------------------------------------------
 *
 * Front matter is conventionally YAML, and this reads a deliberate subset of
 * it: one `key: value` per line, no nesting, no anchors, no multi-line
 * scalars. Lists are written inline as `a, b, c` where a field wants one.
 *
 * That subset covers every field the wiki defines, and the alternative is a
 * dependency whose whole feature set -- merge keys, tags, custom types -- is
 * surface area pointed at operator-supplied files for no gain. A page that
 * tries to use the rest of YAML gets a value it can see is wrong, on the page
 * itself, rather than an exception on somebody's first visit.
 *
 * Nothing here trusts the input: an unterminated block, a key with no colon,
 * or a file that is one long line all yield an empty set of fields and an
 * untouched body.
 */
final readonly class FrontMatter
{
    /**
     * @param  array<string, string>  $fields  Keys lowercased, values trimmed.
     */
    public function __construct(
        public array $fields,
        public string $body,
    ) {}

    /**
     * Split a file's contents into its metadata and its body.
     */
    public static function parse(string $contents): self
    {
        // A byte-order mark would otherwise stop the opening fence matching,
        // and an editor on Windows is a likely source of both it and \r\n.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace("\r\n", "\n", $contents);

        if (! str_starts_with($contents, "---\n")) {
            return new self([], trim($contents));
        }

        $rest = substr($contents, 4);
        $end = strpos($rest, "\n---");

        // An opening fence with no closing one. The file is malformed, so it
        // is treated as a body with no metadata rather than as metadata that
        // happens to run to the end of the page.
        if ($end === false) {
            return new self([], trim($contents));
        }

        $block = substr($rest, 0, $end);
        $body = ltrim(substr($rest, $end + 4), "-\n");

        return new self(self::fields($block), trim($body));
    }

    /**
     * A field, or null when it is absent or empty.
     */
    public function get(string $key): ?string
    {
        $value = $this->fields[$key] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * A field read as a whole number, or null when it is absent or not one.
     */
    public function integer(string $key): ?int
    {
        $value = $this->get($key);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1
            ? (int) $value
            : null;
    }

    /**
     * A comma-separated field, as a list with the empty entries dropped.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function fields(string $block): array
    {
        $fields = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            // Blank lines and comments. A line with no colon is neither a key
            // nor anything this understands, so it is skipped rather than
            // guessed at.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                continue;
            }

            $key = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            // Quoting is how a value keeps a leading space or a trailing
            // colon, so the quotes themselves are not part of it.
            if (strlen($value) >= 2) {
                $first = $value[0];

                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
