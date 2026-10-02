<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\ProbesServerReachability;
use App\Support\Rathena\ServerEndpoint;

/**
 * A reachability probe that answers from a script instead of a socket.
 *
 * Tests must not depend on whatever happens to be listening on the machine
 * running them, and a real probe against a closed port also costs the
 * configured timeout per call.
 */
final class FakeServerProbe implements ProbesServerReachability
{
    /** @var array<string, bool> */
    private array $overrides = [];

    public function __construct(private bool $default = true) {}

    /**
     * Answer differently for one kind of process ('login', 'char', 'map'),
     * so a test can take a single server down.
     */
    public function setReachable(string $kind, bool $reachable): self
    {
        $this->overrides[$kind] = $reachable;

        return $this;
    }

    public function isReachable(ServerEndpoint $endpoint): bool
    {
        return $this->overrides[$endpoint->kind] ?? $this->default;
    }

    public function probe(ServerEndpoint $endpoint): bool
    {
        return $this->isReachable($endpoint);
    }

    public function forget(ServerEndpoint $endpoint): void
    {
        //
    }
}
