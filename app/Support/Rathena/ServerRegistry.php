<?php

declare(strict_types=1);

namespace App\Support\Rathena;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The set of configured server groups, and which one the current request is
 * working against.
 *
 * Laravel's config/database.php is static, but the number of rAthena
 * connections is not known until config/rathena.php has been read: a panel may
 * front several server groups, each with up to four databases plus one per
 * char/map pair. This class therefore synthesises those connection
 * definitions and pushes them into the config repository during boot, so the
 * rest of the application can simply name a connection.
 *
 * See docs/MIGRATION_DECISIONS.md (D5).
 */
final class ServerRegistry
{
    /** @var Collection<string, ServerGroup>|null */
    private ?Collection $groups = null;

    private ?string $currentGroupKey = null;

    private ?string $currentCharMapKey = null;

    private bool $connectionsRegistered = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @return Collection<string, ServerGroup>
     */
    public function all(): Collection
    {
        if ($this->groups instanceof Collection) {
            return $this->groups;
        }

        $groups = Collection::make($this->config->get('rathena.groups', []))
            ->map(fn (array $group, string $key): ServerGroup => ServerGroup::fromConfig($key, $group));

        if ($groups->isEmpty()) {
            throw new RuntimeException(
                'No rAthena server groups are configured. Add at least one entry to config/rathena.php.'
            );
        }

        return $this->groups = $groups;
    }

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }

    public function get(string $key): ServerGroup
    {
        $group = $this->all()->get($key);

        if (! $group instanceof ServerGroup) {
            throw new RuntimeException("Unknown rAthena server group '{$key}'.");
        }

        return $group;
    }

    public function default(): ServerGroup
    {
        $key = (string) $this->config->get('rathena.default', '');

        return $this->has($key)
            ? $this->get($key)
            : $this->firstGroup();
    }

    /**
     * The group this request is working against.
     */
    public function current(): ServerGroup
    {
        return $this->currentGroupKey !== null && $this->has($this->currentGroupKey)
            ? $this->get($this->currentGroupKey)
            : $this->default();
    }

    /**
     * The char/map pair this request is working against.
     */
    public function currentCharMapServer(): CharMapServer
    {
        $group = $this->current();

        return $this->currentCharMapKey !== null && $group->hasCharMapServer($this->currentCharMapKey)
            ? $group->charMapServer($this->currentCharMapKey)
            : $group->defaultCharMapServer();
    }

    /**
     * Point the registry at a group, and optionally a char/map pair within
     * it. Unknown keys are ignored rather than fatal: the value usually
     * arrives from a session or a query string, so a stale one should fall
     * back to the default rather than break the request.
     */
    public function use(?string $groupKey, ?string $charMapKey = null): void
    {
        if ($groupKey !== null && $this->has($groupKey)) {
            $this->currentGroupKey = $groupKey;
            $this->currentCharMapKey = null;
        }

        if ($charMapKey !== null && $this->current()->hasCharMapServer($charMapKey)) {
            $this->currentCharMapKey = $charMapKey;
        }
    }

    /**
     * Register a Laravel database connection for every database of every
     * group. Idempotent, so calling it from a service provider boot is safe.
     */
    public function registerConnections(): void
    {
        if ($this->connectionsRegistered) {
            return;
        }

        $defaults = (array) $this->config->get('rathena.connection_defaults', []);
        $connections = (array) $this->config->get('database.connections', []);

        foreach ($this->all() as $group) {
            foreach ($this->connectionsFor($group, $defaults) as $name => $definition) {
                $connections[$name] = $definition;
            }
        }

        $this->config->set('database.connections', $connections);
        $this->connectionsRegistered = true;
    }

    /**
     * Discard a group's resolved connections so the next query reconnects.
     * Used after configuration changes, and by tests that repoint a group.
     */
    public function purgeConnections(): void
    {
        foreach ($this->all() as $group) {
            $this->database->purge($group->loginConnection());
            $this->database->purge($group->logsConnection());
            $this->database->purge($group->webConnection());

            foreach ($group->charMapServers as $pair) {
                $this->database->purge($pair->connectionName());
            }
        }
    }

    /**
     * Connection definitions for one group.
     *
     * The login, logs and web databases map one-to-one onto connections. The
     * char/map role is different: every pair gets its own connection, because
     * pairs may legitimately use different character databases, and each pair
     * falls back to the group's char_map credentials when it does not
     * override them.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, array<string, mixed>>
     */
    private function connectionsFor(ServerGroup $group, array $defaults): array
    {
        $definitions = [
            ConnectionNaming::login($group->key) => array_merge($defaults, $group->databaseConfig('login')),
            ConnectionNaming::logs($group->key) => array_merge($defaults, $group->databaseConfig('logs')),
            ConnectionNaming::web($group->key) => array_merge($defaults, $group->databaseConfig('web')),
        ];

        $charMapDefaults = $group->databaseConfig('char_map');

        foreach ($group->charMapServers as $pair) {
            $definitions[$pair->connectionName()] = array_merge(
                $defaults,
                $charMapDefaults,
                $pair->databaseOverrides,
            );
        }

        return $definitions;
    }

    private function firstGroup(): ServerGroup
    {
        $first = $this->all()->first();

        if (! $first instanceof ServerGroup) {
            throw new RuntimeException('No rAthena server groups are configured.');
        }

        return $first;
    }
}
