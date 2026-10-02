<?php

declare(strict_types=1);

namespace App\Support\Rathena;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * One login server, the char/map pairs that share it, and the databases they
 * all use.
 *
 * This is the unit a visitor switches between, and the unit every query is
 * scoped to. It replaces FluxCP's Flux_LoginAthenaGroup.
 */
final readonly class ServerGroup
{
    /**
     * @param  Collection<string, CharMapServer>  $charMapServers
     * @param  array<string, array<string, mixed>>  $databases
     */
    public function __construct(
        public string $key,
        public string $name,
        public LoginServer $loginServer,
        public Collection $charMapServers,
        private array $databases,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $charMapServers = Collection::make($config['char_map_servers'] ?? [])
            ->map(fn (array $pair, string $pairKey): CharMapServer => CharMapServer::fromConfig($key, $pairKey, $pair));

        if ($charMapServers->isEmpty()) {
            throw new RuntimeException(
                "Server group '{$key}' declares no char/map servers. At least one is required."
            );
        }

        return new self(
            key: $key,
            name: (string) ($config['name'] ?? $key),
            loginServer: LoginServer::fromConfig($config['login'] ?? []),
            charMapServers: $charMapServers,
            databases: $config['databases'] ?? [],
        );
    }

    /**
     * The char/map pair used when a visitor has not chosen one.
     */
    public function defaultCharMapServer(): CharMapServer
    {
        $first = $this->charMapServers->first();

        if (! $first instanceof CharMapServer) {
            throw new RuntimeException("Server group '{$this->key}' has no char/map servers.");
        }

        return $first;
    }

    public function charMapServer(?string $key): CharMapServer
    {
        if ($key === null) {
            return $this->defaultCharMapServer();
        }

        $pair = $this->charMapServers->get($key);

        if (! $pair instanceof CharMapServer) {
            throw new RuntimeException(
                "Server group '{$this->key}' has no char/map server named '{$key}'."
            );
        }

        return $pair;
    }

    public function hasCharMapServer(string $key): bool
    {
        return $this->charMapServers->has($key);
    }

    public function hasManyCharMapServers(): bool
    {
        return $this->charMapServers->count() > 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Connection names
    |--------------------------------------------------------------------------
    */

    public function loginConnection(): string
    {
        return ConnectionNaming::login($this->key);
    }

    public function charMapConnection(?string $charMapKey = null): string
    {
        return $this->charMapServer($charMapKey)->connectionName();
    }

    public function logsConnection(): string
    {
        return ConnectionNaming::logs($this->key);
    }

    public function webConnection(): string
    {
        return ConnectionNaming::web($this->key);
    }

    /**
     * Whether the login and char/map databases live on the same MySQL server.
     *
     * This decides whether a query may join `login` to `char`. FluxCP always
     * assumed it could, because it interpolated database names straight into
     * SQL -- which silently requires the two to be co-located. Several pages
     * depend on that join, the rankings most visibly, so the panel has to know
     * when it is not available rather than emit SQL that cannot run.
     *
     * Compared on host and port rather than on database name: two different
     * databases on one server can be joined, two databases on different hosts
     * cannot.
     */
    public function loginAndCharMapShareServer(?string $charMapKey = null): bool
    {
        $login = $this->databaseConfig('login');
        $charMap = array_merge(
            $this->databaseConfig('char_map'),
            $this->charMapServer($charMapKey)->databaseOverrides,
        );

        return (string) ($login['host'] ?? '') === (string) ($charMap['host'] ?? '')
            && (string) ($login['port'] ?? '') === (string) ($charMap['port'] ?? '');
    }

    /**
     * The login database's name, for qualifying a table in a cross-database
     * join. Only meaningful when loginAndCharMapShareServer() is true.
     */
    public function loginDatabaseName(): string
    {
        return (string) ($this->databaseConfig('login')['database'] ?? '');
    }

    /**
     * Raw credentials for one of the group's four database roles.
     *
     * @return array<string, mixed>
     */
    public function databaseConfig(string $role): array
    {
        if (! isset($this->databases[$role])) {
            throw new RuntimeException(
                "Server group '{$this->key}' does not configure the '{$role}' database."
            );
        }

        return $this->databases[$role];
    }

    /**
     * @return list<string>
     */
    public function databaseRoles(): array
    {
        return array_keys($this->databases);
    }
}
