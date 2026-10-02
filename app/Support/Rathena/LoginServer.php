<?php

declare(strict_types=1);

namespace App\Support\Rathena;

/**
 * The login server of a server group.
 *
 * Carries the network address used for status probes and the two settings
 * that decide how stored credentials are compared. Both come from the
 * emulator's own configuration and cannot be chosen freely by the panel:
 * see docs/MIGRATION_DECISIONS.md (D1).
 */
final readonly class LoginServer
{
    public function __construct(
        public string $address,
        public int $port,
        /**
         * Whether rAthena stores an unsalted MD5 of the password rather than
         * the password itself. Mirrors FluxCP's LoginServer.UseMD5.
         */
        public bool $usesMd5,
        /**
         * Whether account names are compared case-sensitively. This is the
         * inverse of FluxCP's LoginServer.NoCase, named positively here
         * because a double negative in a conditional reads badly.
         */
        public bool $caseSensitive,
        /**
         * rAthena group_id given to newly registered accounts.
         */
        public int $defaultGroupId,
    ) {}

    /**
     * The login server's network endpoint, for status probing.
     */
    public function endpoint(): ServerEndpoint
    {
        return new ServerEndpoint('login', $this->address, $this->port);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            address: (string) ($config['address'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 6900),
            usesMd5: (bool) ($config['use_md5'] ?? false),
            caseSensitive: (bool) ($config['case_sensitive'] ?? false),
            defaultGroupId: (int) ($config['default_group_id'] ?? 0),
        );
    }
}
