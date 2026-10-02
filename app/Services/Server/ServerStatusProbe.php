<?php

declare(strict_types=1);

namespace App\Services\Server;

use App\Contracts\ProbesServerReachability;
use App\Support\Rathena\ServerEndpoint;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Determines whether an emulator process is accepting connections.
 *
 * The check is the same one FluxCP made -- open a TCP socket and see whether
 * it connects -- because it is the only thing available: rAthena exposes no
 * status endpoint, and a process can be running while refusing connections.
 *
 * What differs is when it happens. The legacy panel probed every server on
 * every page view, with no caching, so a firewalled port made the whole site
 * hang for ServerStatusTimeout seconds per server on every request. Results
 * are cached here, which also makes the figure cheap enough to broadcast.
 */
final class ServerStatusProbe implements ProbesServerReachability
{
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Whether the endpoint is accepting connections, using a cached result
     * when one is available.
     */
    public function isReachable(ServerEndpoint $endpoint): bool
    {
        $ttl = (int) config('panel.server_status.cache_seconds', 30);

        if ($ttl <= 0) {
            return $this->probe($endpoint);
        }

        return (bool) $this->cache->remember(
            $this->cacheKey($endpoint),
            $ttl,
            fn (): bool => $this->probe($endpoint),
        );
    }

    /**
     * Probe the endpoint, ignoring any cached result.
     *
     * Used by the scheduled task that refreshes status for broadcasting, so
     * the broadcast reflects a fresh measurement rather than re-reading its
     * own cache.
     */
    public function probe(ServerEndpoint $endpoint): bool
    {
        if ($endpoint->port <= 0) {
            return false;
        }

        $timeout = (float) config('panel.server_status.timeout_seconds', 2);

        /*
         * The error output is suppressed deliberately. A refused connection is
         * the expected answer for a server that is down, not an exceptional
         * condition, and letting it surface as a PHP warning would fill the
         * log with noise every time a server is offline.
         */
        $socket = @fsockopen($endpoint->address, $endpoint->port, $errno, $errstr, $timeout);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Discard the cached result for an endpoint.
     */
    public function forget(ServerEndpoint $endpoint): void
    {
        $this->cache->forget($this->cacheKey($endpoint));
    }

    private function cacheKey(ServerEndpoint $endpoint): string
    {
        return "server-status:{$endpoint->kind}:{$endpoint->address}:{$endpoint->port}";
    }
}
