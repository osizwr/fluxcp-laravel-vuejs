<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Rathena\ServerEndpoint;

/**
 * Decides whether an emulator process is accepting connections.
 *
 * Behind an interface so the status service can be exercised without opening a
 * socket. A test that had to make a real TCP connection would depend on
 * whatever happens to be listening on the developer's machine, which is the
 * opposite of a test.
 */
interface ProbesServerReachability
{
    /**
     * Whether the endpoint is accepting connections, using a cached result
     * when one is available.
     */
    public function isReachable(ServerEndpoint $endpoint): bool;

    /**
     * Probe the endpoint, ignoring any cached result.
     */
    public function probe(ServerEndpoint $endpoint): bool;

    /**
     * Discard any cached result for the endpoint.
     */
    public function forget(ServerEndpoint $endpoint): void;
}
