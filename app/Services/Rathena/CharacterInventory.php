<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Models\Character;
use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * What a character is carrying, and who they are carrying it with.
 *
 * Ports the four data sets `modules/character/view.php` assembled and an
 * earlier revision of this port dropped: worn equipment and inventory, cart
 * contents, the friend list and the party roster. The item decoding itself
 * lives in ItemStacks, because guild storage needs the same work.
 */
final readonly class CharacterInventory
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
        private ItemStacks $stacks,
    ) {}

    /**
     * Worn equipment and carried inventory, equipped items first.
     *
     * @param  bool  $includeUnidentified  Gated on `SeeUnknownItems` by the
     *                                     caller, as in the legacy.
     * @return list<array<string, mixed>>
     */
    public function inventory(Character $character, bool $includeUnidentified = false): array
    {
        return $this->stacks->read(
            $this->serverFor($character),
            'inventory',
            fn (Builder $query): Builder => $query->where('inventory.char_id', $character->char_id),
            $includeUnidentified,
            equippedFirst: true,
        );
    }

    /**
     * Cart contents.
     *
     * @return list<array<string, mixed>>
     */
    public function cart(Character $character, bool $includeUnidentified = false): array
    {
        return $this->stacks->read(
            $this->serverFor($character),
            'cart_inventory',
            fn (Builder $query): Builder => $query->where('cart_inventory.char_id', $character->char_id),
            $includeUnidentified,
        );
    }

    /**
     * The character's friend list.
     *
     * rAthena keeps friendship one-directional, so this is who the character
     * added -- the reverse is a separate row that may not exist.
     *
     * @return list<array<string, mixed>>
     */
    public function friends(Character $character): array
    {
        $rows = $this->connections->connection($this->serverFor($character)->connectionName())
            ->table('friends as f')
            ->join('char as fr', 'fr.char_id', '=', 'f.friend_id')
            ->leftJoin('guild as g', 'g.guild_id', '=', 'fr.guild_id')
            ->select([
                'fr.char_id', 'fr.name', 'fr.class', 'fr.base_level', 'fr.job_level',
                'fr.online', 'g.guild_id', 'g.name as guild_name', 'g.emblem_id',
            ])
            ->where('f.char_id', $character->char_id)
            ->orderBy('fr.name')
            ->get();

        return $rows->map(fn (object $row): array => $this->asCompanion($row))->all();
    }

    /**
     * The other members of the character's party.
     *
     * Empty when the character is in no party. The legacy ran this query only
     * when `party_leader_id` was set, which skipped the roster for every
     * member who was not the leader; the condition here is membership.
     *
     * @return list<array<string, mixed>>
     */
    public function partyMembers(Character $character): array
    {
        $partyId = (int) ($character->party_id ?? 0);

        if ($partyId === 0) {
            return [];
        }

        $rows = $this->connections->connection($this->serverFor($character)->connectionName())
            ->table('char as p')
            ->leftJoin('guild as g', 'g.guild_id', '=', 'p.guild_id')
            ->select([
                'p.char_id', 'p.name', 'p.class', 'p.base_level', 'p.job_level',
                'p.online', 'g.guild_id', 'g.name as guild_name', 'g.emblem_id',
            ])
            ->where('p.party_id', $partyId)
            ->where('p.char_id', '!=', $character->char_id)
            ->orderBy('p.name')
            ->get();

        return $rows->map(fn (object $row): array => $this->asCompanion($row))->all();
    }

    /**
     * A friend or party member, shaped the same either way so one component
     * renders both lists.
     *
     * @return array<string, mixed>
     */
    private function asCompanion(object $row): array
    {
        $guildId = (int) ($row->guild_id ?? 0);

        return [
            'id' => (int) $row->char_id,
            'name' => (string) $row->name,
            'job_id' => (int) $row->class,
            'base_level' => (int) $row->base_level,
            'job_level' => (int) $row->job_level,
            'online' => (int) ($row->online ?? 0) > 0,
            'guild' => $guildId > 0
                ? [
                    'id' => $guildId,
                    'name' => (string) ($row->guild_name ?? ''),
                    'emblem_url' => (int) ($row->emblem_id ?? 0) > 0
                        ? "/api/guilds/{$guildId}/emblem"
                        : null,
                ]
                : null,
        ];
    }

    private function serverFor(Character $character): CharMapServer
    {
        return $this->servers->currentCharMapServer();
    }
}
