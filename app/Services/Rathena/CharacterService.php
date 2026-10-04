<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Enums\CharacterActionResult;
use App\Models\Character;
use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;

/**
 * Character maintenance: slots, appearance, position, marriage, preferences.
 *
 * Ports Flux_Athena::resetLook(), ::resetPosition() and the bodies of
 * modules/character/changeslot.php, divorce.php and prefs.php.
 *
 * ---------------------------------------------------------------------------
 * Why everything here refuses while the character is online
 * ---------------------------------------------------------------------------
 *
 * rAthena loads a character into the map server's memory on login and writes
 * it back on logout. A row changed in the database meanwhile is overwritten
 * when that happens, so the change appears to work and then silently reverts
 * -- or, for a slot swap, two characters end up in one slot because only half
 * the change survived. The legacy panel checked this for the same reason, and
 * every method here does it before touching anything.
 */
final readonly class CharacterService
{
    /**
     * The character preferences a player controls.
     *
     * Stored in `cp_charprefs` as name/value rows rather than columns, because
     * that is the table FluxCP created and an existing install has data in it.
     */
    private const PREFERENCES = [
        'HideFromWhosOnline',
        'HideMapFromWhosOnline',
        'HideFromZenyRanking',
    ];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
        private StaffVisibility $staff,
    ) {}

    /**
     * @return list<string>
     */
    public static function preferenceNames(): array
    {
        return self::PREFERENCES;
    }

    /*
    |--------------------------------------------------------------------------
    | Slots
    |--------------------------------------------------------------------------
    */

    /**
     * Move a character to another slot, swapping with whoever is there.
     *
     * `char_num` is zero-based in the database and one-based everywhere a
     * player sees it, which is the kind of mismatch that produces an off-by-one
     * in exactly one of the two branches. The conversion happens once, here.
     */
    public function changeSlot(Character $character, int $slot): CharacterActionResult
    {
        $server = $this->serverFor($character);

        if ($slot < 1 || $slot > $server->maxCharacterSlots) {
            return CharacterActionResult::SlotOutOfRange;
        }

        $target = $slot - 1;

        if ($character->char_num === $target) {
            return CharacterActionResult::SlotUnchanged;
        }

        if ($character->online) {
            return CharacterActionResult::CharacterOnline;
        }

        $connection = $this->connections->connection($server->connectionName());

        $occupant = $connection->table('char')
            ->where('account_id', $character->account_id)
            ->where('char_num', $target)
            ->where('char_id', '!=', $character->char_id)
            ->first();

        if ($occupant !== null && (int) $occupant->online !== 0) {
            return CharacterActionResult::SlotOccupiedByOnlineCharacter;
        }

        /*
         * Both moves in one transaction. The legacy did them as two
         * statements, so a failure between them left both characters in the
         * same slot -- which rAthena's character select screen renders by
         * showing one of them and hiding the other.
         */
        $connection->transaction(function () use ($connection, $character, $occupant, $target): void {
            if ($occupant !== null) {
                $connection->table('char')
                    ->where('char_id', $occupant->char_id)
                    ->update(['char_num' => $character->char_num]);
            }

            $connection->table('char')
                ->where('char_id', $character->char_id)
                ->update(['char_num' => $target]);
        });

        $character->char_num = $target;
        $character->syncOriginalAttribute('char_num');

        return CharacterActionResult::Done;
    }

    /*
    |--------------------------------------------------------------------------
    | Appearance and position
    |--------------------------------------------------------------------------
    */

    /**
     * Clear a character's appearance back to the default.
     *
     * Unequips everything first. A character wearing a headgear whose sprite
     * the client cannot load is the usual reason somebody needs this -- their
     * client crashes on entering the world -- so leaving the item equipped
     * would not fix anything.
     *
     * `body` is set to the class rather than to zero, matching the legacy
     * statement: zero is a valid sprite, and setting it would leave the
     * character looking like a novice.
     */
    public function resetLook(Character $character): CharacterActionResult
    {
        if ($character->online) {
            return CharacterActionResult::CharacterOnline;
        }

        $connection = $this->connections->connection($this->serverFor($character)->connectionName());

        $connection->transaction(function () use ($connection, $character): void {
            $connection->table('inventory')
                ->where('char_id', $character->char_id)
                ->update(['equip' => 0]);

            $connection->table('char')
                ->where('char_id', $character->char_id)
                ->update([
                    'hair' => 1,
                    'hair_color' => 0,
                    'clothes_color' => 0,
                    'weapon' => 0,
                    'shield' => 0,
                    'head_top' => 0,
                    'head_mid' => 0,
                    'head_bottom' => 0,
                    'body' => $character->class,
                ]);
        });

        return CharacterActionResult::Done;
    }

    /**
     * Move a character back to their save point.
     *
     * The operator's deny list exists so this cannot be used to walk out of a
     * PvP or event map for free. Map names are compared without their `.gat`
     * extension, because rAthena writes them both ways depending on version.
     */
    public function resetPosition(Character $character): CharacterActionResult
    {
        if ($character->online) {
            return CharacterActionResult::CharacterOnline;
        }

        $server = $this->serverFor($character);
        $current = $this->mapName((string) $character->last_map);

        foreach ($server->resetDenyMaps as $denied) {
            if ($current === $this->mapName((string) $denied)) {
                return CharacterActionResult::MapNotPermitted;
            }
        }

        $connection = $this->connections->connection($server->connectionName());

        $connection->table('char')
            ->where('char_id', $character->char_id)
            ->update([
                'last_map' => $connection->raw('save_map'),
                'last_x' => $connection->raw('save_x'),
                'last_y' => $connection->raw('save_y'),
            ]);

        return CharacterActionResult::Done;
    }

    /*
    |--------------------------------------------------------------------------
    | Marriage
    |--------------------------------------------------------------------------
    */

    /**
     * End a marriage.
     *
     * Both partners, and the child when one exists and the operator does not
     * keep it, have to be offline: the relationship is held on three rows and
     * a partial write leaves one character married to somebody who is not
     * married to them.
     */
    public function divorce(Character $character): CharacterActionResult
    {
        if (($character->partner_id ?? 0) <= 0) {
            return CharacterActionResult::NotMarried;
        }

        $server = $this->serverFor($character);
        $connection = $this->connections->connection($server->connectionName());

        $partner = Character::query()->find($character->partner_id);

        if (! $partner instanceof Character) {
            return CharacterActionResult::PartnerMissing;
        }

        if ($character->online) {
            return CharacterActionResult::CharacterOnline;
        }

        if ($partner->online) {
            return CharacterActionResult::PartnerOnline;
        }

        $keepChild = config('panel.characters.divorce_keeps_child') === true;
        $child = ($character->child ?? 0) > 0 ? Character::query()->find($character->child) : null;

        if (! $keepChild && $child instanceof Character && $child->online) {
            return CharacterActionResult::ChildOnline;
        }

        $connection->transaction(function () use (
            $connection, $character, $partner, $child, $keepChild
        ): void {
            $columns = ['partner_id' => 0];

            if (! $keepChild) {
                $columns['child'] = 0;
            }

            $connection->table('char')
                ->whereIn('char_id', [$character->char_id, $partner->char_id])
                ->update($columns);

            if (! $keepChild && $child instanceof Character) {
                $connection->table('char')
                    ->where('char_id', $child->char_id)
                    ->update(['father' => 0, 'mother' => 0]);
            }

            if (config('panel.characters.divorce_keeps_rings') !== true) {
                $this->removeWeddingRings($connection, [$character->char_id, $partner->char_id]);
            }
        });

        $character->partner_id = 0;
        $character->syncOriginalAttribute('partner_id');

        return CharacterActionResult::Done;
    }

    /**
     * Delete the wedding rings bound to these characters.
     *
     * rAthena stores the partner's character id split across two signed
     * `card` columns: the low sixteen bits in card2 and the high sixteen in
     * card3. The low half is written as a signed value, so an id whose low
     * word exceeds 32767 is stored negative -- which is why this cannot be a
     * plain equality against the id.
     *
     * Items 2634 and 2635 are the wedding rings, and `card0 = 255` marks a
     * bound item.
     *
     * @param  list<int>  $charIds
     */
    private function removeWeddingRings($connection, array $charIds): void
    {
        $connection->table('inventory')
            ->whereIn('char_id', $charIds)
            ->whereIn('nameid', [2634, 2635])
            ->where('card0', 255)
            ->where(function ($query) use ($charIds): void {
                foreach ($charIds as $charId) {
                    $low = $charId & 0xFFFF;

                    $query->orWhere(function ($q) use ($low, $charId): void {
                        $q->where('card2', $low > 32767 ? $low - 65536 : $low)
                            ->where('card3', ($charId & 0xFFFF0000) >> 16);
                    });
                }
            })
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Preferences
    |--------------------------------------------------------------------------
    */

    /**
     * A character's preferences, with every known one present.
     *
     * Absent rows read as false rather than being left out, so the client
     * renders a complete form rather than one that grows a checkbox the first
     * time somebody ticks it.
     *
     * @return array<string, bool>
     */
    public function preferences(Character $character): array
    {
        $stored = $this->connections
            ->connection($this->serverFor($character)->connectionName())
            ->table('cp_charprefs')
            ->where('char_id', $character->char_id)
            ->pluck('value', 'name');

        $preferences = [];

        foreach (self::PREFERENCES as $name) {
            $preferences[$name] = (string) ($stored[$name] ?? '') === '1';
        }

        return $preferences;
    }

    /**
     * Write preferences, ignoring any name that is not one of ours.
     *
     * @param  array<string, bool>  $values
     * @return array<string, bool> The preferences as they now stand.
     */
    public function setPreferences(Character $character, array $values): array
    {
        $connection = $this->connections
            ->connection($this->serverFor($character)->connectionName());

        foreach ($values as $name => $enabled) {
            if (! in_array($name, self::PREFERENCES, true)) {
                continue;
            }

            $connection->table('cp_charprefs')->updateOrInsert(
                ['char_id' => $character->char_id, 'name' => $name],
                ['value' => $enabled ? '1' : '0'],
            );
        }

        return $this->preferences($character);
    }

    /*
    |--------------------------------------------------------------------------
    | Map statistics
    |--------------------------------------------------------------------------
    */

    /**
     * How many characters are on each map right now.
     *
     * Honours the per-character "hide my map" preference, so a player who has
     * asked not to be locatable is counted on no map at all rather than being
     * findable by elimination.
     *
     * @return Collection<int, object>
     */
    public function mapStatistics(int $limit = 50): Collection
    {
        $server = $this->servers->currentCharMapServer();
        $connection = $this->connections->connection($server->connectionName());

        $query = $connection->table('char as ch')
            ->selectRaw('ch.last_map as map, count(*) as players')
            ->leftJoin('cp_charprefs as hidden', function ($join): void {
                $join->on('hidden.char_id', '=', 'ch.char_id')
                    ->where('hidden.name', '=', 'HideMapFromWhosOnline');
            })
            ->where('ch.online', 1)
            ->where(fn ($q) => $q->whereNull('hidden.value')->orWhere('hidden.value', '!=', '1'));

        /*
         * Staff are left off the counts as well, which the per-character
         * preference does not cover: a game master alone on a map is located
         * by a count of one whether or not they set a preference. FluxCP's
         * HideFromMapStats.
         */
        $threshold = config('panel.characters.hide_maps_at_or_above_level');

        $this->staff->excludeStaff(
            $query,
            $this->servers->current(),
            $server->key,
            'ch',
            $threshold === null ? null : (int) $threshold,
        );

        return Collection::make(
            $query->groupBy('ch.last_map')
                ->orderByDesc('players')
                ->orderBy('ch.last_map')
                ->limit($limit)
                ->get(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function serverFor(Character $character): CharMapServer
    {
        return $this->servers->currentCharMapServer();
    }

    /**
     * A map name without its extension.
     *
     * rAthena has written `prontera` and `prontera.gat` to this column
     * depending on version, and a deny list that compares the raw strings
     * misses on whichever form it was not written with.
     */
    private function mapName(string $map): string
    {
        return strtolower(preg_replace('/\.gat$/i', '', trim($map)) ?? '');
    }
}
