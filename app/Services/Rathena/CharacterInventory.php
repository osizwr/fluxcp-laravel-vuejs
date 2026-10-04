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
        private ReferenceTables $reference,
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
     * The character's pet, named.
     *
     * The pet's species name comes through the monster merge, so a pet of a
     * mob a server added itself is named by that server's own entry. The
     * legacy joined both the base and override mob tables side by side and
     * took whichever was not null, which is the same answer by hand.
     *
     * @return array<string, mixed>|null
     */
    public function pet(Character $character): ?array
    {
        $petId = (int) ($character->pet_id ?? 0);

        if ($petId === 0) {
            return null;
        }

        $server = $this->serverFor($character);

        $row = $this->connections->connection($server->connectionName())
            ->table('pet')
            ->select(['pet_id', 'class', 'name', 'level', 'intimate', 'hungry', 'equip'])
            ->where('pet_id', $petId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->pet_id,
            'name' => (string) $row->name,
            'species_id' => (int) $row->class,
            'species' => $this->monsterName((int) $row->class, $server),
            'level' => (int) $row->level,
            /*
             * Reported as rAthena stores them rather than as a share of a
             * maximum: the ceilings are server configuration the panel cannot
             * see, so a percentage here would be invented.
             */
            'intimacy' => (int) $row->intimate,
            'hunger' => (int) $row->hungry,
            'has_accessory' => (int) ($row->equip ?? 0) > 0,
        ];
    }

    /**
     * The character's homunculus, with the stat block the legacy showed.
     *
     * A released homunculus stays in the table with `alive` cleared, so that
     * column is reported rather than used to hide the row -- a player asking
     * where their homunculus went is answered by seeing it listed as not
     * alive.
     *
     * @return array<string, mixed>|null
     */
    public function homunculus(Character $character): ?array
    {
        $homunId = (int) ($character->homun_id ?? 0);

        if ($homunId === 0) {
            return null;
        }

        $row = $this->connections->connection($this->serverFor($character)->connectionName())
            ->table('homunculus')
            ->where('homun_id', $homunId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->homun_id,
            'name' => (string) $row->name,
            'class_id' => (int) $row->class,
            'class' => $this->homunculusName((int) $row->class),
            'level' => (int) $row->level,
            'experience' => (int) ($row->exp ?? 0),
            'intimacy' => (int) ($row->intimacy ?? 0),
            'hunger' => (int) ($row->hunger ?? 0),
            'alive' => (int) ($row->alive ?? 0) > 0,
            'skill_points' => (int) ($row->skill_point ?? 0),
            'stats' => [
                'str' => (int) ($row->str ?? 0),
                'agi' => (int) ($row->agi ?? 0),
                'vit' => (int) ($row->vit ?? 0),
                'int' => (int) ($row->int ?? 0),
                'dex' => (int) ($row->dex ?? 0),
                'luk' => (int) ($row->luk ?? 0),
            ],
            'hp' => ['current' => (int) ($row->hp ?? 0), 'max' => (int) ($row->max_hp ?? 0)],
            'sp' => ['current' => (int) ($row->sp ?? 0), 'max' => (int) ($row->max_sp ?? 0)],
        ];
    }

    /**
     * Partner, parents and child, by name.
     *
     * Four columns on `char`, each a character id, which the legacy resolved
     * with four self-joins. One query over the ids it needs does the same.
     *
     * @return array<string, array{id: int, name: string}|null>
     */
    public function family(Character $character): array
    {
        $relations = [
            'partner' => (int) ($character->partner_id ?? 0),
            'mother' => (int) ($character->mother ?? 0),
            'father' => (int) ($character->father ?? 0),
            'child' => (int) ($character->child ?? 0),
        ];

        $ids = array_values(array_filter($relations));

        if ($ids === []) {
            return array_map(static fn (): null => null, $relations);
        }

        $names = $this->connections->connection($this->serverFor($character)->connectionName())
            ->table('char')
            ->select(['char_id', 'name'])
            ->whereIn('char_id', $ids)
            ->pluck('name', 'char_id');

        $family = [];

        foreach ($relations as $relation => $id) {
            /*
             * An id that names nobody is reported as absent. rAthena leaves
             * these columns set after the other character is deleted, so a
             * character can have a partner id and no partner.
             */
            $family[$relation] = $id > 0 && $names->has($id)
                ? ['id' => $id, 'name' => (string) $names->get($id)]
                : null;
        }

        return $family;
    }

    /**
     * The character's party, with its leader.
     *
     * @return array<string, mixed>|null
     */
    public function party(Character $character): ?array
    {
        $partyId = (int) ($character->party_id ?? 0);

        if ($partyId === 0) {
            return null;
        }

        $row = $this->connections->connection($this->serverFor($character)->connectionName())
            ->table('party as p')
            ->leftJoin('char as leader', 'leader.char_id', '=', 'p.leader_char')
            ->select(['p.party_id', 'p.name', 'p.exp', 'p.item', 'p.leader_char', 'leader.name as leader_name'])
            ->where('p.party_id', $partyId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->party_id,
            'name' => (string) $row->name,
            'leader' => (int) ($row->leader_char ?? 0) > 0
                ? ['id' => (int) $row->leader_char, 'name' => (string) ($row->leader_name ?? '')]
                : null,
            'shares_experience' => (int) ($row->exp ?? 0) > 0,
            'shares_items' => (int) ($row->item ?? 0) > 0,
        ];
    }

    /**
     * How many times the character has died.
     *
     * rAthena keeps this in `char_reg_num` under `PC_DIE_COUNTER` rather than
     * as a column, and a character who has never died has no row, so an
     * absent row is zero rather than unknown.
     */
    public function deathCount(Character $character): int
    {
        return (int) ($this->connections->connection($this->serverFor($character)->connectionName())
            ->table('char_reg_num')
            ->where('char_id', $character->char_id)
            ->where('key', 'PC_DIE_COUNTER')
            ->value('value') ?? 0);
    }

    /**
     * The character's rank within their guild.
     *
     * @return array<string, mixed>|null
     */
    public function guildPosition(Character $character): ?array
    {
        $guildId = (int) ($character->guild_id ?? 0);

        if ($guildId === 0) {
            return null;
        }

        $row = $this->connections->connection($this->serverFor($character)->connectionName())
            ->table('guild_member as m')
            ->leftJoin('guild_position as pos', function ($join): void {
                $join->on('pos.guild_id', '=', 'm.guild_id')
                    ->on('pos.position', '=', 'm.position');
            })
            ->select(['m.position', 'm.exp as devotion', 'pos.name', 'pos.mode', 'pos.exp_mode'])
            ->where('m.guild_id', $guildId)
            ->where('m.char_id', $character->char_id)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'position' => (int) ($row->position ?? 0),
            // rAthena pads some varchar columns with a literal `|00`.
            'name' => str_replace('|00', '', (string) ($row->name ?? '')),
            'mode' => (int) ($row->mode ?? 0),
            'guild_tax' => (int) ($row->exp_mode ?? 0),
            'devotion' => (int) ($row->devotion ?? 0),
        ];
    }

    /**
     * A monster's name through the merge, for naming a pet's species.
     */
    private function monsterName(int $classId, CharMapServer $server): ?string
    {
        if ($classId === 0) {
            return null;
        }

        $key = $this->reference->keyColumn('monsters', $server);

        $row = $this->reference->monsters($server)
            ->where("monsters.{$key}", $classId)
            ->first();

        if ($row === null) {
            return null;
        }

        foreach (['iName', 'name_english', 'kName', 'name_japanese'] as $column) {
            $value = $row->{$column} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function homunculusName(int $classId): ?string
    {
        $map = (array) config('rathena_reference.homunculus', []);

        return isset($map[$classId]) ? (string) $map[$classId] : null;
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
