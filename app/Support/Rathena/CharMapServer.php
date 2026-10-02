<?php

declare(strict_types=1);

namespace App\Support\Rathena;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * A char/map server pair within a server group.
 *
 * rAthena runs the character and map servers as a pair sharing one character
 * database, and a single login server may front several pairs. Each pair has
 * its own rates, War of Emperium schedule and timezone.
 */
final readonly class CharMapServer
{
    /**
     * @param  Collection<int, WoeWindow>  $woeWindows
     * @param  array<string, int>  $rates
     * @param  list<string>  $resetDenyMaps
     * @param  list<string>  $woeRestrictedRoutes
     * @param  array<string, mixed>  $databaseOverrides  Connection keys this
     *         pair overrides on top of the group's char_map credentials,
     *         mirroring FluxCP's per-pair 'Database' setting.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $groupKey,
        public bool $renewal,
        public int $maxCharacterSlots,
        public ServerEndpoint $charServer,
        public ServerEndpoint $mapServer,
        public Collection $woeWindows,
        public array $rates,
        public array $resetDenyMaps,
        public array $woeRestrictedRoutes,
        public array $databaseOverrides,
        private ?string $timezone,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $groupKey, string $key, array $config): self
    {
        $windows = Collection::make($config['woe_schedule'] ?? [])
            ->map(fn (array $window): WoeWindow => WoeWindow::fromConfig($window))
            ->values();

        return new self(
            key: $key,
            name: (string) ($config['name'] ?? $key),
            groupKey: $groupKey,
            renewal: (bool) ($config['renewal'] ?? true),
            maxCharacterSlots: (int) ($config['max_character_slots'] ?? 9),
            charServer: ServerEndpoint::fromConfig('char', $config['char_server'] ?? []),
            mapServer: ServerEndpoint::fromConfig('map', $config['map_server'] ?? []),
            woeWindows: $windows,
            rates: array_map('intval', $config['rates'] ?? []),
            resetDenyMaps: array_values(array_map('strval', $config['reset_deny_maps'] ?? ['sec_pri'])),
            woeRestrictedRoutes: array_values(array_map('strval', $config['woe_restricted_routes'] ?? [])),
            databaseOverrides: array_filter(
                $config['database'] ?? [],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ),
            timezone: isset($config['timezone']) && $config['timezone'] !== ''
                ? (string) $config['timezone']
                : null,
        );
    }

    /**
     * The timezone this pair's clock runs in, falling back to the
     * application timezone when the pair does not declare one.
     */
    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone ?? (string) config('app.timezone', 'UTC'));
    }

    /**
     * The pair's current local time.
     */
    public function serverTime(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * Whether War of Emperium is running right now.
     */
    public function isWoeActive(?CarbonImmutable $at = null): bool
    {
        $moment = $at ?? CarbonImmutable::now();
        $timezone = $this->timezone();

        return $this->woeWindows->contains(
            fn (WoeWindow $window): bool => $window->contains($moment, $timezone)
        );
    }

    /**
     * When War of Emperium next starts, or null if no schedule is configured.
     */
    public function nextWoeStart(?CarbonImmutable $at = null): ?CarbonImmutable
    {
        if ($this->woeWindows->isEmpty()) {
            return null;
        }

        $moment = $at ?? CarbonImmutable::now();
        $timezone = $this->timezone();

        return $this->woeWindows
            ->map(fn (WoeWindow $window): CarbonImmutable => $window->nextStart($moment, $timezone))
            ->sort()
            ->first();
    }

    /**
     * Whether the named route is refused while War of Emperium is running.
     */
    public function restrictsRouteDuringWoe(string $routeName): bool
    {
        return in_array($routeName, $this->woeRestrictedRoutes, strict: true);
    }

    /**
     * Laravel connection name for this pair's character database.
     */
    public function connectionName(): string
    {
        return ConnectionNaming::charMap($this->groupKey, $this->key);
    }
}
