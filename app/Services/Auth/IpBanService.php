<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\Rathena\ServerGroup;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Checks an address against rAthena's `ipbanlist`.
 *
 * This is the emulator's own ban table, shared with the game server, so the
 * panel must read it exactly the way rAthena writes it. Entries are stored as
 * dotted-quad strings with trailing octets replaced by a literal `*`, and are
 * matched by generating the four patterns that could cover an address rather
 * than by any range arithmetic:
 *
 *     203.0.113.7  ->  203.*.*.*
 *                      203.0.*.*
 *                      203.0.113.*
 *                      203.0.113.7
 *
 * `rtime` is when the ban expires, so only rows still in the future count.
 */
final class IpBanService
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
    ) {}

    /**
     * Whether the address is currently banned on this server group.
     *
     * Returns false for anything that is not an IPv4 address. The ban table's
     * address columns were widened to 39 characters for IPv6, but rAthena's
     * own matching is still octet-and-wildcard based and has no IPv6
     * equivalent, so an IPv6 client cannot be matched against it. Reporting
     * "not banned" is the honest answer rather than a guess, and the
     * limitation is recorded in docs/COMPATIBILITY_REPORT.md.
     */
    public function isBanned(ServerGroup $group, string $address): bool
    {
        $patterns = $this->patternsFor($address);

        if ($patterns === []) {
            return false;
        }

        return $this->connections
            ->connection($group->loginConnection())
            ->table('ipbanlist')
            ->whereIn('list', $patterns)
            ->whereRaw('rtime > NOW()')
            ->exists();
    }

    /**
     * The `ipbanlist` entries that would cover this address.
     *
     * @return list<string>
     */
    public function patternsFor(string $address): array
    {
        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }

        [$a, $b, $c, $d] = explode('.', $address);

        return [
            "{$a}.*.*.*",
            "{$a}.{$b}.*.*",
            "{$a}.{$b}.{$c}.*",
            "{$a}.{$b}.{$c}.{$d}",
        ];
    }
}
