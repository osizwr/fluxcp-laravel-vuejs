<?php

declare(strict_types=1);

namespace App\Support\Rathena;

/**
 * A TCP endpoint belonging to one of the emulator's three server processes.
 *
 * Deliberately inert: it describes where a server is, and nothing about
 * whether it is running. Reachability is the job of
 * {@see \App\Services\Server\ServerStatusProbe}, which keeps the network call
 * out of the value object so status can be cached and faked in tests.
 */
final readonly class ServerEndpoint
{
    public function __construct(
        /** One of 'login', 'char' or 'map'. */
        public string $kind,
        public string $address,
        public int $port,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $kind, array $config): self
    {
        return new self(
            kind: $kind,
            address: (string) ($config['address'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 0),
        );
    }

    public function __toString(): string
    {
        return "{$this->address}:{$this->port}";
    }
}
