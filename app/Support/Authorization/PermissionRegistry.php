<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\AccountLevel;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * The single authority on what a given privilege level may do.
 *
 * Everything it knows comes from config/permissions.php, which is a direct
 * port of FluxCP's config/access.php. Nothing else in the application should
 * compare account levels by hand.
 */
final class PermissionRegistry
{
    /** @var array<string, AccountLevel>|null */
    private ?array $routes = null;

    /** @var array<string, AccountLevel>|null */
    private ?array $abilities = null;

    /** @var array<int, array{name: string, level: AccountLevel}>|null */
    private ?array $accountGroups = null;

    public function __construct(private readonly ConfigRepository $config) {}

    /*
    |--------------------------------------------------------------------------
    | Route permissions
    |--------------------------------------------------------------------------
    */

    /**
     * The level required to reach a route, or null when the route has no
     * entry in the map.
     *
     * Callers must treat null as "deny", not "allow". Returning null rather
     * than a level keeps that decision at the call site, where it can be
     * logged as a configuration error -- the legacy panel's equivalent of
     * this case silently served the page to everyone (D3).
     */
    public function routeRequirement(string $routeName): ?AccountLevel
    {
        return $this->routeMap()[$routeName] ?? null;
    }

    public function hasRoute(string $routeName): bool
    {
        return array_key_exists($routeName, $this->routeMap());
    }

    /**
     * @return array<string, AccountLevel>
     */
    public function routeMap(): array
    {
        return $this->routes ??= array_merge(
            $this->normalise('permissions.routes'),
            $this->normalise('permissions.added_routes'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Abilities
    |--------------------------------------------------------------------------
    */

    /**
     * The level required for a named ability.
     *
     * An unknown ability resolves to Noone rather than null. Abilities are
     * referenced from code and templates by literal name, so a typo must fail
     * closed; this is also what makes the legacy `allowedToDonate` defect
     * impossible to reintroduce silently (D10).
     */
    public function abilityRequirement(string $ability): AccountLevel
    {
        return $this->abilityMap()[$ability] ?? AccountLevel::Noone;
    }

    public function hasAbility(string $ability): bool
    {
        return array_key_exists($ability, $this->abilityMap());
    }

    /**
     * @return array<string, AccountLevel>
     */
    public function abilityMap(): array
    {
        return $this->abilities ??= $this->normalise('permissions.abilities');
    }

    /**
     * Every ability a holder of this level may use. Used to hand the client a
     * permission set so the UI can hide what the viewer cannot do -- while
     * the server still enforces every one of them independently.
     *
     * @return list<string>
     */
    public function abilitiesFor(AccountLevel $level): array
    {
        $granted = [];

        foreach ($this->abilityMap() as $ability => $required) {
            if ($level->satisfies($required)) {
                $granted[] = $ability;
            }
        }

        return $granted;
    }

    /*
    |--------------------------------------------------------------------------
    | rAthena account groups
    |--------------------------------------------------------------------------
    */

    /**
     * Map an rAthena group_id onto a panel privilege level.
     *
     * An unmapped ID becomes a plain player, matching the legacy
     * AccountLevel::getGroupLevel() fallback. Negative group IDs are rAthena's
     * marker for a disabled account and are never granted a level here --
     * account state is checked separately during authentication.
     */
    public function levelForGroupId(int $groupId): AccountLevel
    {
        return $this->accountGroupMap()[$groupId]['level'] ?? AccountLevel::Player;
    }

    /**
     * The operator-facing name of an rAthena group, as configured.
     */
    public function nameForGroupId(int $groupId): string
    {
        return $this->accountGroupMap()[$groupId]['name'] ?? "Group {$groupId}";
    }

    /**
     * @return array<int, array{name: string, level: AccountLevel}>
     */
    public function accountGroupMap(): array
    {
        if ($this->accountGroups !== null) {
            return $this->accountGroups;
        }

        $groups = [];

        foreach ((array) $this->config->get('permissions.account_groups', []) as $id => $group) {
            $level = $group['level'] ?? null;

            if (! $level instanceof AccountLevel) {
                throw new InvalidArgumentException(
                    "Account group {$id} in config/permissions.php must declare an AccountLevel."
                );
            }

            $groups[(int) $id] = [
                'name' => (string) ($group['name'] ?? "Group {$id}"),
                'level' => $level,
            ];
        }

        return $this->accountGroups = $groups;
    }

    /**
     * @return array<string, AccountLevel>
     */
    private function normalise(string $configKey): array
    {
        $map = [];

        foreach ((array) $this->config->get($configKey, []) as $key => $level) {
            if (! $level instanceof AccountLevel) {
                throw new InvalidArgumentException(
                    "Permission '{$key}' in config/permissions.php must be an AccountLevel, "
                    .get_debug_type($level).' given.'
                );
            }

            $map[(string) $key] = $level;
        }

        return $map;
    }
}
