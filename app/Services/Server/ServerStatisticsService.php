<?php

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\Gender;
use App\Services\Rathena\ReferenceData;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Aggregate counts over the game database.
 *
 * Every figure here is a real query against rAthena's own tables. Nothing is
 * estimated or padded, which is why the set is small: these are the aggregates
 * the emulator's schema actually supports.
 *
 * Notably absent is server uptime. The panel can tell whether a process is
 * accepting connections right now, but rAthena records no start time anywhere
 * the panel can read, so an uptime figure would have to be invented. It is
 * omitted rather than guessed.
 *
 * Counts are cached. On a large server these are full-table counts, and the
 * blocks that display them sit on the front page, so running them per visitor
 * would make the landing page the most expensive query in the application.
 */
final readonly class ServerStatisticsService
{
    public function __construct(
        private ServerRegistry $servers,
        private ConnectionResolverInterface $connections,
        private CacheRepository $cache,
        private ReferenceData $reference,
    ) {}

    /**
     * Headline counts.
     *
     * @return array{accounts: int, characters: int, guilds: int, players_online: int}
     */
    public function summary(): array
    {
        return $this->remember('summary', fn (): array => [
            'accounts' => $this->countAccounts(),
            'characters' => $this->countCharacters(),
            'guilds' => $this->countGuilds(),
            'parties' => $this->countParties(),
            'zeny' => $this->totalZeny(),
            'players_online' => $this->countOnline(),
        ]);
    }

    /**
     * Parties in existence.
     *
     * Counted separately from guilds because they are a different table and a
     * different thing: a party is temporary and a guild is not.
     *
     * Returns null when the table is absent, which it is on a server old
     * enough or trimmed enough not to have one. Null renders as "not
     * available" rather than as zero, which would read as "nobody has a
     * party".
     */
    private function countParties(): ?int
    {
        $connection = $this->connections->connection(
            $this->servers->currentCharMapServer()->connectionName(),
        );

        if (! $connection->getSchemaBuilder()->hasTable('party')) {
            return null;
        }

        return $connection->table('party')->count();
    }

    /**
     * All the zeny held by characters.
     *
     * The legacy server information page showed this, and it is the figure an
     * operator watches for inflation. Excludes characters queued for deletion,
     * whose zeny is about to stop existing.
     */
    private function totalZeny(): int
    {
        return (int) $this->connections
            ->connection($this->servers->currentCharMapServer()->connectionName())
            ->table('char')
            ->where(fn ($q) => $q->where('delete_date', 0)->orWhereNull('delete_date'))
            ->sum('zeny');
    }

    /**
     * How many characters there are of each job class.
     *
     * A genuine `GROUP BY class` over the character table, with the names
     * resolved server-side from the ported reference data, so a client never
     * has to carry its own copy of 153 job names.
     *
     * @return Collection<int, array{job_id: int, job_name: string, characters: int}>
     */
    public function classDistribution(int $limit = 8): Collection
    {
        $limit = max(1, min($limit, 50));

        /** @var list<array{job_id: int, job_name: string, characters: int}> $rows */
        $rows = $this->remember("classes:{$limit}", fn (): array => $this->guard(
            fn (): array => $this->connections
                ->connection($this->servers->currentCharMapServer()->connectionName())
                ->table('char')
                ->selectRaw('class, COUNT(*) as characters')
                ->where(fn ($query) => $query->where('delete_date', 0)->orWhereNull('delete_date'))
                ->groupBy('class')
                ->orderByDesc('characters')
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'job_id' => (int) $row->class,
                    'job_name' => $this->reference->jobName((int) $row->class),
                    'characters' => (int) $row->characters,
                ])
                ->all(),
            [],
        ));

        return Collection::make($rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Counts
    |--------------------------------------------------------------------------
    */

    private function countAccounts(): int
    {
        // Players only: rAthena's own inter-server accounts are marked 'S' and
        // a negative group_id means the account is disabled.
        return $this->guard(fn (): int => (int) $this->connections
            ->connection($this->servers->current()->loginConnection())
            ->table('login')
            ->where('sex', '!=', Gender::Server->value)
            ->where('group_id', '>=', 0)
            ->count(), 0);
    }

    private function countCharacters(): int
    {
        return $this->guard(fn (): int => (int) $this->connections
            ->connection($this->servers->currentCharMapServer()->connectionName())
            ->table('char')
            ->where(fn ($query) => $query->where('delete_date', 0)->orWhereNull('delete_date'))
            ->count(), 0);
    }

    private function countGuilds(): int
    {
        return $this->guard(fn (): int => (int) $this->connections
            ->connection($this->servers->currentCharMapServer()->connectionName())
            ->table('guild')
            ->count(), 0);
    }

    private function countOnline(): int
    {
        return $this->guard(fn (): int => (int) $this->connections
            ->connection($this->servers->currentCharMapServer()->connectionName())
            ->table('char')
            ->where('online', '>', 0)
            ->count(), 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    private function remember(string $key, callable $compute): mixed
    {
        $ttl = (int) config('panel.statistics.cache_seconds', 300);
        $group = $this->servers->current()->key;
        $pair = $this->servers->currentCharMapServer()->key;

        if ($ttl <= 0) {
            return $compute();
        }

        return $this->cache->remember("statistics:{$group}:{$pair}:{$key}", $ttl, $compute);
    }

    /**
     * Fall back rather than fail when the game database is unreachable.
     *
     * These figures decorate a landing page. A database that is down should
     * make the page report zero alongside the servers showing as offline, not
     * take the whole site with it.
     *
     * @template T
     *
     * @param  callable(): T  $query
     * @param  T  $default
     * @return T
     */
    private function guard(callable $query, mixed $default): mixed
    {
        try {
            return $query();
        } catch (Throwable) {
            return $default;
        }
    }
}
