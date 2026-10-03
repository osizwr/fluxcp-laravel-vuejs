<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a character maintenance action did or did not happen.
 *
 * FluxCP signalled these by returning `true`, `false`, `-1`, `-2` or `-3` from
 * the same method and comparing with `==`, which is how `-1` and `true` end up
 * meaning the same thing. Each outcome is a case here, so the caller cannot
 * confuse "character is online" with "it worked".
 */
enum CharacterActionResult: string
{
    case Done = 'done';

    /**
     * rAthena holds character state in memory while the player is connected
     * and writes it back on logout, so a change made in the database now would
     * be overwritten -- or worse, half-applied. Every one of these actions
     * therefore refuses while the character is online.
     */
    case CharacterOnline = 'character_online';

    case PartnerOnline = 'partner_online';

    case ChildOnline = 'child_online';

    /**
     * Resetting position is refused from the maps the operator listed, which
     * exist so somebody cannot use it to escape a PvP or event map.
     */
    case MapNotPermitted = 'map_not_permitted';

    case NotMarried = 'not_married';

    case PartnerMissing = 'partner_missing';

    case SlotOccupiedByOnlineCharacter = 'slot_occupied_by_online_character';

    case SlotUnchanged = 'slot_unchanged';

    case SlotOutOfRange = 'slot_out_of_range';

    public function succeeded(): bool
    {
        return $this === self::Done;
    }

    /**
     * The message shown to the person who attempted it.
     *
     * Each names the character or the obstacle, because "that did not work" on
     * a page with several characters tells somebody nothing about which one.
     */
    public function message(string $characterName = ''): string
    {
        $name = $characterName === '' ? 'That character' : $characterName;

        return match ($this) {
            self::Done => 'Done.',
            self::CharacterOnline => "{$name} is online. Log out in the game first.",
            self::PartnerOnline => "{$name}'s partner is online. Both must be logged out.",
            self::ChildOnline => "{$name}'s child is online. Everyone involved must be logged out.",
            self::MapNotPermitted => "{$name} cannot be moved from the map they are on.",
            self::NotMarried => "{$name} is not married.",
            self::PartnerMissing => "{$name}'s partner no longer exists.",
            self::SlotOccupiedByOnlineCharacter => 'The character in that slot is online.',
            self::SlotUnchanged => 'That is already the slot this character is in.',
            self::SlotOutOfRange => 'That slot number does not exist on this server.',
        };
    }
}
