<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\Reference\MergedTable;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * The item and monster tables, with the server's custom entries merged in.
 *
 * The single place anything in this application reads item or monster data
 * from. Thirteen legacy actions need it -- the item and monster browsers, the
 * item shop, character equipment, the pick, zeny and feeding logs, the MVP
 * ranking and the vending listings -- and each of them in FluxCP built its own
 * temporary table inline.
 *
 * Going through one service is what stops a later page being written against
 * `item_db` directly. That page would look right on a vanilla install and be
 * wrong on every server that has added an item, which is the failure this is
 * here to prevent rather than merely to fix once.
 *
 * See docs/MIGRATION_DECISIONS.md (D6).
 */
final readonly class ReferenceTables
{
    public function __construct(
        private MergedTable $merge,
        private ServerRegistry $servers,
    ) {}

    /**
     * Items, merged, as a query ready to filter and paginate.
     */
    public function items(?CharMapServer $server = null): Builder
    {
        return $this->merged('items', $server);
    }

    /**
     * Monsters, merged.
     */
    public function monsters(?CharMapServer $server = null): Builder
    {
        return $this->merged('monsters', $server);
    }

    /**
     * The column rows are keyed on, for callers that need to join or look up
     * by id. Detected from the schema rather than assumed -- rAthena has used
     * both `id` and `ID` for these across versions.
     */
    public function keyColumn(string $kind, ?CharMapServer $server = null): string
    {
        $server ??= $this->servers->currentCharMapServer();

        return $this->merge->keyColumn(
            $server->connectionName(),
            $this->tablesFor($kind, $server)['base'],
        );
    }

    /**
     * The columns the base table has, for a caller that needs to know whether
     * this server's schema models a given attribute.
     *
     * Needed because rAthena's schema is not fixed: the mob table has used
     * `ID`/`iName`/`LV` and `id`/`name_english`/`level` across versions, and
     * the item table gains attribute columns as the emulator adds features. A
     * panel that assumes one shape renders an empty listing on the other.
     *
     * @return list<string>
     */
    public function columns(string $kind, ?CharMapServer $server = null): array
    {
        $server ??= $this->servers->currentCharMapServer();

        return $this->merge->columns(
            $server->connectionName(),
            $this->tablesFor($kind, $server)['base'],
        );
    }

    /**
     * @return list<string>
     */
    public function itemColumns(?CharMapServer $server = null): array
    {
        return $this->columns('items', $server);
    }

    /**
     * @return list<string>
     */
    public function monsterColumns(?CharMapServer $server = null): array
    {
        return $this->columns('monsters', $server);
    }

    /**
     * Which physical tables a kind resolves to for a given world.
     *
     * Exposed because the diagnostics command and the tests report it, and
     * because an operator debugging "my custom items do not show up" needs to
     * be told which tables were actually read.
     *
     * @return array{base: string, override: string|null, alias: string}
     */
    public function tablesFor(string $kind, ?CharMapServer $server = null): array
    {
        $server ??= $this->servers->currentCharMapServer();

        $config = (array) config("rathena.reference_tables.{$kind}");

        if ($config === []) {
            throw new InvalidArgumentException(
                "Unknown reference table [{$kind}]. Expected one of: "
                .implode(', ', array_keys((array) config('rathena.reference_tables', []))).'.'
            );
        }

        $pair = (array) ($config[$server->renewal ? 'renewal' : 'pre_renewal'] ?? []);

        return [
            'base' => (string) ($pair['base'] ?? ''),
            'override' => ($pair['override'] ?? null) === null ? null : (string) $pair['override'],
            'alias' => (string) ($config['alias'] ?? $kind),
        ];
    }

    private function merged(string $kind, ?CharMapServer $server): Builder
    {
        $server ??= $this->servers->currentCharMapServer();

        $tables = $this->tablesFor($kind, $server);

        return $this->merge->query(
            $server->connectionName(),
            $tables['base'],
            $tables['override'],
            $tables['alias'],
        );
    }
}
