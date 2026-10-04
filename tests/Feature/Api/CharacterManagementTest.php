<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Character maintenance.
 *
 * Ports the coverage for character/view, changeslot, resetlook, resetpos,
 * divorce, prefs and mapstats.
 *
 * The recurring theme is that every one of these refuses while the character
 * is online. rAthena keeps the character in memory and writes it back on
 * logout, so a change made meanwhile is silently reverted -- the action
 * appears to work and then undoes itself, which is worse than being refused.
 */
final class CharacterManagementTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    /**
     * @return array{0: Account, 1: Character}
     */
    private function signedInWithCharacter(array $state = []): array
    {
        $account = Account::factory()->create();
        $character = Character::factory()->forAccount($account)->named('Mine')->state($state)->create();

        $this->actingAs($account);

        return [$account, $character];
    }

    /*
    |--------------------------------------------------------------------------
    | Viewing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_player_can_view_their_own_character(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Mine')
            ->assertJsonStructure(['meta' => ['preferences']]);
    }

    #[Test]
    public function a_player_cannot_view_another_accounts_character(): void
    {
        $theirs = Character::factory()->forAccount(Account::factory()->create())->named('Theirs')->create();

        $this->actingAs(Account::factory()->create())
            ->getJson("/api/characters/{$theirs->char_id}")
            ->assertStatus(403);
    }

    #[Test]
    public function staff_with_the_ability_can_view_any_character(): void
    {
        $theirs = Character::factory()->forAccount(Account::factory()->create())->named('Theirs')->create();

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson("/api/characters/{$theirs->char_id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Theirs');
    }

    #[Test]
    public function a_guest_cannot_view_a_character(): void
    {
        $character = Character::factory()->forAccount(Account::factory()->create())->create();

        $this->getJson("/api/characters/{$character->char_id}")->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Belongings
    |--------------------------------------------------------------------------
    |
    | The four data sets modules/character/view.php assembled: equipment and
    | inventory, cart, friends and party.
    |
    */

    #[Test]
    public function a_character_page_lists_equipment_and_inventory(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->defineItem(1201, 'Knife', slots: 3);
        $this->defineItem(501, 'Red Potion');

        $this->carry($character->char_id, 1201, equip: 2, refine: 7);
        $this->carry($character->char_id, 501, amount: 10);

        $response = $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(2, 'meta.inventory');

        // Worn items first, whatever their item id.
        $response->assertJsonPath('meta.inventory.0.item_id', 1201)
            ->assertJsonPath('meta.inventory.0.name', 'Knife')
            ->assertJsonPath('meta.inventory.0.equipped', true)
            ->assertJsonPath('meta.inventory.0.refine', 7)
            ->assertJsonPath('meta.inventory.0.slots', 3)
            ->assertJsonPath('meta.inventory.1.item_id', 501)
            ->assertJsonPath('meta.inventory.1.amount', 10)
            ->assertJsonPath('meta.inventory.1.equipped', false);
    }

    #[Test]
    public function slotted_cards_are_named(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->defineItem(1201, 'Knife', slots: 2);
        $this->defineItem(4001, 'Poring Card');

        $this->carry($character->char_id, 1201, cards: [4001, 0, 0, 0]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.inventory.0.cards')
            ->assertJsonPath('meta.inventory.0.cards.0.name', 'Poring Card')
            // Two slots, one card: nothing over.
            ->assertJsonPath('meta.inventory.0.cards_over', 0);
    }

    /**
     * More cards than slots cannot happen on a stock server, so it is reported
     * rather than corrected.
     */
    #[Test]
    public function an_over_slotted_item_reports_how_many_cards_are_extra(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->defineItem(1201, 'Knife', slots: 1);
        $this->defineItem(4001, 'Poring Card');
        $this->defineItem(4002, 'Fabre Card');

        $this->carry($character->char_id, 1201, cards: [4001, 4002, 0, 0]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.inventory.0.cards_over', 1);
    }

    /**
     * When card0 marks a forged item, card2 and card3 are the two halves of
     * the forger's character id -- not cards. Reading them as cards names
     * items that are not there.
     */
    #[Test]
    public function a_forged_item_names_its_forger_instead_of_cards(): void
    {
        [$account, $character] = $this->signedInWithCharacter();

        $forger = Character::factory()->forAccount($account)->named('Smith')->create();

        $this->defineItem(1201, 'Knife', slots: 0);

        $charId = (int) $forger->char_id;

        $this->carry($character->char_id, 1201, cards: [
            255,
            0,
            // The low half is stored signed, so a value above 32767 arrives
            // negative and has to be widened back.
            $charId & 0xFFFF,
            $charId >> 16,
        ]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.inventory.0.creation_kind', 'forged')
            ->assertJsonPath('meta.inventory.0.created_by', 'Smith')
            ->assertJsonCount(0, 'meta.inventory.0.cards')
            ->assertJsonPath('meta.inventory.0.cards_over', 0);
    }

    #[Test]
    public function a_forger_id_above_the_signed_range_is_widened(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->defineItem(1201, 'Knife', slots: 0);

        // 40000 does not fit in a signed 16-bit column, so rAthena stores it
        // as a negative number.
        $forgerId = 40_000;

        $this->carry($character->char_id, 1201, cards: [
            255, 0, $forgerId - 65_536, 0,
        ]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.inventory.0.created_by_char_id', $forgerId);
    }

    #[Test]
    public function an_unidentified_item_is_hidden_from_the_owner_and_shown_to_staff(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->defineItem(501, 'Red Potion');
        $this->defineItem(603, 'Old Blue Box');

        $this->carry($character->char_id, 501);
        $this->carry($character->char_id, 603, identified: false);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.inventory')
            ->assertJsonPath('meta.inventory.0.item_id', 501)
            ->assertJsonPath('meta.shows_unidentified', false);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(2, 'meta.inventory')
            ->assertJsonPath('meta.shows_unidentified', true);
    }

    #[Test]
    public function a_character_page_lists_the_cart(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->defineItem(501, 'Red Potion');

        DB::connection($this->charMap())->table('cart_inventory')->insert([
            'char_id' => $character->char_id, 'nameid' => 501,
            'amount' => 25, 'identify' => true,
        ]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.cart')
            ->assertJsonPath('meta.cart.0.name', 'Red Potion')
            ->assertJsonPath('meta.cart.0.amount', 25);
    }

    /**
     * rAthena keeps friendship one-directional, so being added does not put
     * the adder on your list.
     */
    #[Test]
    public function a_character_page_lists_friends_in_one_direction_only(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $friend = Character::factory()->forAccount(Account::factory()->create())
            ->named('Companion')->state(['online' => 1])->create();

        DB::connection($this->charMap())->table('friends')->insert([
            'char_id' => $character->char_id,
            'friend_account' => $friend->account_id,
            'friend_id' => $friend->char_id,
        ]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.friends')
            ->assertJsonPath('meta.friends.0.name', 'Companion')
            ->assertJsonPath('meta.friends.0.online', true);

        // The friend has not added them back, so their list is empty.
        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson("/api/characters/{$friend->char_id}")
            ->assertOk()
            ->assertJsonCount(0, 'meta.friends');
    }

    /**
     * The legacy only ran the party query when the character was the leader,
     * which left every other member looking party-less.
     */
    #[Test]
    public function a_party_roster_is_listed_for_a_member_who_is_not_the_leader(): void
    {
        [, $character] = $this->signedInWithCharacter(['party_id' => 7]);

        Character::factory()->forAccount(Account::factory()->create())
            ->named('Leader')->state(['party_id' => 7])->create();

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.party_members')
            ->assertJsonPath('meta.party_members.0.name', 'Leader');
    }

    #[Test]
    public function a_character_in_no_party_has_an_empty_roster(): void
    {
        [, $character] = $this->signedInWithCharacter(['party_id' => 0]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(0, 'meta.party_members');
    }

    /*
    |--------------------------------------------------------------------------
    | Pet, homunculus, family, party and deaths
    |--------------------------------------------------------------------------
    |
    | All of these were in the single query modules/character/view.php ran and
    | none were in this port. They were found by checking every table the
    | legacy reads against whether anything here touches it.
    |
    */

    #[Test]
    public function a_character_page_shows_its_pet(): void
    {
        [, $character] = $this->signedInWithCharacter();

        DB::connection($this->charMap())->table('mob_db_re')->insert([
            'ID' => 1002, 'Sprite' => 'PORING', 'kName' => 'Poring', 'iName' => 'Poring',
        ]);

        $petId = DB::connection($this->charMap())->table('pet')->insertGetId([
            'class' => 1002, 'name' => 'Blobby', 'char_id' => $character->char_id,
            'account_id' => $character->account_id, 'level' => 12,
            'intimate' => 900, 'hungry' => 50,
        ]);

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['pet_id' => $petId]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.pet.name', 'Blobby')
            // The species comes through the monster merge, so a pet of a mob
            // the server added itself is named by that server's entry.
            ->assertJsonPath('meta.pet.species', 'Poring')
            ->assertJsonPath('meta.pet.level', 12)
            ->assertJsonPath('meta.pet.intimacy', 900);
    }

    #[Test]
    public function a_character_with_no_pet_reports_null(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.pet', null);
    }

    #[Test]
    public function a_character_page_shows_its_homunculus_with_its_stats(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $homunId = DB::connection($this->charMap())->table('homunculus')->insertGetId([
            'char_id' => $character->char_id, 'class' => 6001, 'name' => 'Lif',
            'level' => 40, 'exp' => 5000, 'intimacy' => 100_000, 'hunger' => 70,
            'str' => 12, 'agi' => 20, 'vit' => 15, 'int' => 30, 'dex' => 18, 'luk' => 9,
            'hp' => 900, 'max_hp' => 1000, 'sp' => 80, 'max_sp' => 100,
            'skill_point' => 3, 'alive' => true,
        ]);

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['homun_id' => $homunId]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.homunculus.name', 'Lif')
            ->assertJsonPath('meta.homunculus.level', 40)
            ->assertJsonPath('meta.homunculus.stats.int', 30)
            ->assertJsonPath('meta.homunculus.hp.current', 900)
            ->assertJsonPath('meta.homunculus.hp.max', 1000)
            ->assertJsonPath('meta.homunculus.skill_points', 3)
            ->assertJsonPath('meta.homunculus.alive', true);
    }

    /**
     * A released homunculus stays in the table with `alive` cleared. Reported
     * rather than hidden: a player asking where it went is answered by seeing
     * it listed as not alive.
     */
    #[Test]
    public function a_released_homunculus_is_reported_as_not_alive(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $homunId = DB::connection($this->charMap())->table('homunculus')->insertGetId([
            'char_id' => $character->char_id, 'class' => 6001, 'name' => 'Gone',
            'level' => 5, 'alive' => false,
        ]);

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['homun_id' => $homunId]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.homunculus.name', 'Gone')
            ->assertJsonPath('meta.homunculus.alive', false);
    }

    #[Test]
    public function a_character_page_names_its_family(): void
    {
        [$account, $character] = $this->signedInWithCharacter();

        $partner = Character::factory()->forAccount($account)->named('Spouse')->create();
        $child = Character::factory()->forAccount($account)->named('Offspring')->create();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['partner_id' => $partner->char_id, 'child' => $child->char_id]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.family.partner.name', 'Spouse')
            ->assertJsonPath('meta.family.child.name', 'Offspring')
            ->assertJsonPath('meta.family.mother', null)
            ->assertJsonPath('meta.family.father', null);
    }

    /**
     * rAthena leaves these columns set after the other character is deleted,
     * so a character can carry a partner id and have no partner.
     */
    #[Test]
    public function a_family_id_that_names_nobody_is_reported_as_absent(): void
    {
        [, $character] = $this->signedInWithCharacter();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['partner_id' => 999_999]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.family.partner', null);
    }

    #[Test]
    public function a_character_page_names_its_party_and_leader(): void
    {
        [$account, $character] = $this->signedInWithCharacter();

        $leader = Character::factory()->forAccount($account)->named('Captain')->create();

        $partyId = DB::connection($this->charMap())->table('party')->insertGetId([
            'name' => 'The Expedition', 'leader_char' => $leader->char_id,
            'leader_id' => $leader->account_id, 'exp' => true, 'item' => false,
        ]);

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['party_id' => $partyId]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.party.name', 'The Expedition')
            ->assertJsonPath('meta.party.leader.name', 'Captain')
            ->assertJsonPath('meta.party.shares_experience', true)
            ->assertJsonPath('meta.party.shares_items', false);
    }

    /**
     * rAthena keeps this in char_reg_num under PC_DIE_COUNTER rather than as a
     * column, and a character who has never died has no row at all.
     */
    #[Test]
    public function a_character_page_reports_its_death_count(): void
    {
        [, $character] = $this->signedInWithCharacter();

        DB::connection($this->charMap())->table('char_reg_num')->insert([
            'char_id' => $character->char_id, 'key' => 'PC_DIE_COUNTER',
            'index' => 0, 'value' => 17,
        ]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.deaths', 17);
    }

    #[Test]
    public function a_character_who_has_never_died_reports_zero_deaths(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonPath('meta.deaths', 0);
    }

    #[Test]
    public function a_character_page_reports_its_guild_rank(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $guild = \App\Models\Guild::factory()->named('Valkyrie')->create();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)
            ->update(['guild_id' => $guild->guild_id]);

        DB::connection($this->charMap())->table('guild_member')->insert([
            'guild_id' => $guild->guild_id, 'char_id' => $character->char_id,
            'exp' => 320, 'position' => 2,
        ]);

        DB::connection($this->charMap())->table('guild_position')->insert([
            'guild_id' => $guild->guild_id, 'position' => 2,
            'name' => 'Treasurer|00', 'mode' => 1, 'exp_mode' => 30,
        ]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            // The terminator rAthena pads the column with is stripped.
            ->assertJsonPath('meta.guild_position.name', 'Treasurer')
            ->assertJsonPath('meta.guild_position.guild_tax', 30)
            ->assertJsonPath('meta.guild_position.devotion', 320);
    }

    /**
     * @param  list<int>  $cards
     */
    private function carry(
        int $charId,
        int $itemId,
        int $amount = 1,
        int $equip = 0,
        int $refine = 0,
        bool $identified = true,
        array $cards = [0, 0, 0, 0],
    ): void {
        DB::connection($this->charMap())->table('inventory')->insert([
            'char_id' => $charId,
            'nameid' => $itemId,
            'amount' => $amount,
            'equip' => $equip,
            'refine' => $refine,
            'identify' => $identified,
            'card0' => $cards[0],
            'card1' => $cards[1],
            'card2' => $cards[2],
            'card3' => $cards[3],
        ]);
    }

    private function defineItem(int $id, string $name, int $slots = 0): void
    {
        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => $id,
            'name_aegis' => str_replace(' ', '_', $name),
            'name_english' => $name,
            'type' => 'Etc',
            'slots' => $slots,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Slots
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_character_can_be_moved_to_an_empty_slot(): void
    {
        [, $character] = $this->signedInWithCharacter(['char_num' => 0]);

        $this->putJson("/api/characters/{$character->char_id}/slot", ['slot' => 3])
            ->assertOk();

        // Zero-based in the database, one-based to the player.
        $this->assertSame(2, (int) $character->refresh()->char_num);
    }

    #[Test]
    public function moving_into_an_occupied_slot_swaps_the_two(): void
    {
        [$account, $character] = $this->signedInWithCharacter(['char_num' => 0]);

        $occupant = Character::factory()->forAccount($account)->named('Other')
            ->state(['char_num' => 1])->create();

        $this->putJson("/api/characters/{$character->char_id}/slot", ['slot' => 2])
            ->assertOk();

        $this->assertSame(1, (int) $character->refresh()->char_num);
        $this->assertSame(0, (int) $occupant->refresh()->char_num, 'The occupant takes the vacated slot.');
    }

    #[Test]
    public function the_slot_is_not_changed_while_the_character_is_online(): void
    {
        [, $character] = $this->signedInWithCharacter(['char_num' => 0, 'online' => 1]);

        $this->putJson("/api/characters/{$character->char_id}/slot", ['slot' => 3])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'character_online');

        $this->assertSame(0, (int) $character->refresh()->char_num);
    }

    #[Test]
    public function a_swap_is_refused_when_the_occupant_is_online(): void
    {
        /*
         * Half a swap leaves two characters in one slot, which rAthena's
         * character select renders by hiding one of them.
         */
        [$account, $character] = $this->signedInWithCharacter(['char_num' => 0]);

        $occupant = Character::factory()->forAccount($account)
            ->state(['char_num' => 1, 'online' => 1])->create();

        $this->putJson("/api/characters/{$character->char_id}/slot", ['slot' => 2])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'slot_occupied_by_online_character');

        $this->assertSame(0, (int) $character->refresh()->char_num);
        $this->assertSame(1, (int) $occupant->refresh()->char_num);
    }

    #[Test]
    public function a_slot_beyond_the_servers_limit_is_refused(): void
    {
        [, $character] = $this->signedInWithCharacter(['char_num' => 0]);

        $this->putJson("/api/characters/{$character->char_id}/slot", ['slot' => 99])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'slot_out_of_range');
    }

    #[Test]
    public function moving_to_the_current_slot_is_reported_rather_than_done(): void
    {
        [, $character] = $this->signedInWithCharacter(['char_num' => 2]);

        $this->putJson("/api/characters/{$character->char_id}/slot", ['slot' => 3])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'slot_unchanged');
    }

    /*
    |--------------------------------------------------------------------------
    | Appearance
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resetting_a_look_clears_the_appearance_and_unequips(): void
    {
        [, $character] = $this->signedInWithCharacter([
            'class' => 4008,
            'hair' => 15,
            'hair_color' => 6,
            'clothes_color' => 4,
            'head_top' => 2220,
        ]);

        DB::connection($this->charMap())->table('inventory')->insert([
            'char_id' => $character->char_id, 'nameid' => 2220, 'amount' => 1, 'equip' => 256,
        ]);

        $this->postJson("/api/characters/{$character->char_id}/reset-look")->assertOk();

        $row = DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)->first();

        $this->assertSame(1, (int) $row->hair);
        $this->assertSame(0, (int) $row->hair_color);
        $this->assertSame(0, (int) $row->clothes_color);
        $this->assertSame(0, (int) $row->head_top);
        // Set to the class, not zero: zero is a valid sprite and would leave
        // the character looking like a novice.
        $this->assertSame(4008, (int) $row->body);

        $this->assertSame(
            0,
            (int) DB::connection($this->charMap())->table('inventory')
                ->where('char_id', $character->char_id)->value('equip'),
            'The item that likely caused the problem must be unequipped.',
        );
    }

    #[Test]
    public function a_look_is_not_reset_while_online(): void
    {
        [, $character] = $this->signedInWithCharacter(['hair' => 15, 'online' => 1]);

        $this->postJson("/api/characters/{$character->char_id}/reset-look")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'character_online');

        $this->assertSame(15, (int) $character->refresh()->hair);
    }

    /*
    |--------------------------------------------------------------------------
    | Position
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resetting_a_position_moves_the_character_to_their_save_point(): void
    {
        [, $character] = $this->signedInWithCharacter([
            'last_map' => 'gef_dun01', 'last_x' => 50, 'last_y' => 60,
            'save_map' => 'prontera', 'save_x' => 150, 'save_y' => 160,
        ]);

        $this->postJson("/api/characters/{$character->char_id}/reset-position")->assertOk();

        $row = $character->refresh();

        $this->assertSame('prontera', $row->last_map);
        $this->assertSame(150, (int) $row->last_x);
        $this->assertSame(160, (int) $row->last_y);
    }

    #[Test]
    public function a_position_is_not_reset_from_a_denied_map(): void
    {
        /*
         * The deny list exists so this cannot be used to walk out of a PvP or
         * event map for free.
         */
        config(['rathena.groups.main.char_map_servers.main.reset_deny_maps' => ['guild_vs1']]);
        $this->app->forgetInstance(ServerRegistry::class);

        [, $character] = $this->signedInWithCharacter([
            'last_map' => 'guild_vs1', 'save_map' => 'prontera',
        ]);

        $this->postJson("/api/characters/{$character->char_id}/reset-position")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'map_not_permitted');

        $this->assertSame('guild_vs1', $character->refresh()->last_map);
    }

    #[Test]
    public function the_deny_list_matches_regardless_of_the_gat_extension(): void
    {
        // rAthena has written this column both ways depending on version.
        config(['rathena.groups.main.char_map_servers.main.reset_deny_maps' => ['guild_vs1.gat']]);
        $this->app->forgetInstance(ServerRegistry::class);

        [, $character] = $this->signedInWithCharacter([
            'last_map' => 'guild_vs1', 'save_map' => 'prontera',
        ]);

        $this->postJson("/api/characters/{$character->char_id}/reset-position")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'map_not_permitted');
    }

    /*
    |--------------------------------------------------------------------------
    | Divorce
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_married_pair_can_be_divorced(): void
    {
        [$account, $character] = $this->signedInWithCharacter();

        $partner = Character::factory()->forAccount($account)->named('Spouse')->create();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)->update(['partner_id' => $partner->char_id]);
        DB::connection($this->charMap())->table('char')
            ->where('char_id', $partner->char_id)->update(['partner_id' => $character->char_id]);

        $this->postJson("/api/characters/{$character->char_id}/divorce")->assertOk();

        $this->assertSame(0, (int) $character->refresh()->partner_id);
        $this->assertSame(0, (int) $partner->refresh()->partner_id, 'Both sides must be cleared.');
    }

    #[Test]
    public function an_unmarried_character_is_reported_rather_than_silently_succeeding(): void
    {
        [, $character] = $this->signedInWithCharacter(['partner_id' => 0]);

        $this->postJson("/api/characters/{$character->char_id}/divorce")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_married');
    }

    #[Test]
    public function a_divorce_is_refused_while_either_partner_is_online(): void
    {
        [$account, $character] = $this->signedInWithCharacter();

        $partner = Character::factory()->forAccount($account)->state(['online' => 1])->create();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)->update(['partner_id' => $partner->char_id]);

        $this->postJson("/api/characters/{$character->char_id}/divorce")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'partner_online');

        $this->assertSame($partner->char_id, (int) $character->refresh()->partner_id);
    }

    #[Test]
    public function the_wedding_rings_are_removed(): void
    {
        config(['panel.characters.divorce_keeps_rings' => false]);

        [$account, $character] = $this->signedInWithCharacter();
        $partner = Character::factory()->forAccount($account)->create();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)->update(['partner_id' => $partner->char_id]);

        /*
         * rAthena splits the partner's id across two signed card columns: the
         * low word in card2, written negative when it exceeds 32767, and the
         * high word in card3. card0 = 255 marks the item as bound.
         */
        $low = $character->char_id & 0xFFFF;

        DB::connection($this->charMap())->table('inventory')->insert([
            'char_id' => $partner->char_id,
            'nameid' => 2634,
            'amount' => 1,
            'card0' => 255,
            'card2' => $low > 32767 ? $low - 65536 : $low,
            'card3' => ($character->char_id & 0xFFFF0000) >> 16,
        ]);

        $this->postJson("/api/characters/{$character->char_id}/divorce")->assertOk();

        $this->assertSame(
            0,
            DB::connection($this->charMap())->table('inventory')
                ->where('char_id', $partner->char_id)->count(),
        );
    }

    #[Test]
    public function the_rings_survive_when_the_operator_keeps_them(): void
    {
        config(['panel.characters.divorce_keeps_rings' => true]);

        [$account, $character] = $this->signedInWithCharacter();
        $partner = Character::factory()->forAccount($account)->create();

        DB::connection($this->charMap())->table('char')
            ->where('char_id', $character->char_id)->update(['partner_id' => $partner->char_id]);

        DB::connection($this->charMap())->table('inventory')->insert([
            'char_id' => $partner->char_id, 'nameid' => 2634, 'amount' => 1, 'card0' => 255,
        ]);

        $this->postJson("/api/characters/{$character->char_id}/divorce")->assertOk();

        $this->assertSame(
            1,
            DB::connection($this->charMap())->table('inventory')
                ->where('char_id', $partner->char_id)->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Preferences
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function preferences_default_to_off_and_list_every_known_one(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->getJson("/api/characters/{$character->char_id}/preferences")
            ->assertOk()
            ->assertJsonPath('data.HideFromWhosOnline', false)
            ->assertJsonPath('data.HideMapFromWhosOnline', false)
            ->assertJsonPath('data.HideFromZenyRanking', false);
    }

    #[Test]
    public function preferences_can_be_saved(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->putJson("/api/characters/{$character->char_id}/preferences", [
            'HideFromWhosOnline' => true,
        ])->assertOk()->assertJsonPath('data.HideFromWhosOnline', true);

        $this->getJson("/api/characters/{$character->char_id}/preferences")
            ->assertOk()
            ->assertJsonPath('data.HideFromWhosOnline', true);
    }

    #[Test]
    public function an_unknown_preference_name_is_ignored(): void
    {
        [, $character] = $this->signedInWithCharacter();

        $this->putJson("/api/characters/{$character->char_id}/preferences", [
            'HideFromWhosOnline' => true,
            'GiveMeAdmin' => true,
        ])->assertOk();

        $stored = DB::connection($this->charMap())->table('cp_charprefs')
            ->where('char_id', $character->char_id)->pluck('name')->all();

        $this->assertSame(['HideFromWhosOnline'], $stored);
    }

    #[Test]
    public function hiding_from_the_zeny_ladder_needs_its_own_ability(): void
    {
        /*
         * Separate because an operator may want the ladder complete. Dropped
         * from the submission rather than refusing the whole request, so the
         * other preferences still save.
         */
        /*
         * The gate is redefined rather than the config changed: gates are
         * registered at boot with the required level captured by value, so a
         * config change afterwards has no effect — which is itself worth
         * knowing, since it means a test that edits the ability map silently
         * tests nothing.
         */
        Gate::define('HideFromZenyRank', static fn (?Account $account): bool => false);

        [, $character] = $this->signedInWithCharacter();

        $this->putJson("/api/characters/{$character->char_id}/preferences", [
            'HideFromWhosOnline' => true,
            'HideFromZenyRanking' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.HideFromWhosOnline', true)
            ->assertJsonPath('data.HideFromZenyRanking', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Map statistics
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function map_statistics_count_online_characters_per_map(): void
    {
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->online()->count(3)
            ->state(['last_map' => 'prontera'])->create();
        Character::factory()->forAccount($account)->online()->count(1)
            ->state(['last_map' => 'geffen'])->create();
        Character::factory()->forAccount($account)->count(5)
            ->state(['last_map' => 'payon'])->create();

        $response = $this->getJson('/api/characters/maps')->assertOk();

        $response->assertJsonPath('data.0.map', 'prontera')
            ->assertJsonPath('data.0.players', 3)
            ->assertJsonPath('data.1.map', 'geffen')
            ->assertJsonPath('meta.total_online', 4);

        // Offline characters are on no map.
        $this->assertNotContains('payon', array_column($response->json('data'), 'map'));
    }

    #[Test]
    public function a_character_hiding_their_map_is_not_counted(): void
    {
        /*
         * Counting them anyway makes the preference useless: with a count of
         * one on an unusual map, the player is located by elimination.
         */
        $account = Account::factory()->create();

        $hidden = Character::factory()->forAccount($account)->online()
            ->state(['last_map' => 'lighthalzen'])->create();

        DB::connection($this->charMap())->table('cp_charprefs')->insert([
            'char_id' => $hidden->char_id, 'name' => 'HideMapFromWhosOnline', 'value' => '1',
        ]);

        $this->getJson('/api/characters/maps')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * The per-character preference does not cover staff: a game master alone
     * on a map is located by a count of one whether or not they set one.
     * FluxCP's HideFromMapStats.
     */
    #[Test]
    public function a_staff_characters_map_is_not_counted(): void
    {
        Character::factory()->forAccount(Account::factory()->create())->online()
            ->state(['last_map' => 'prontera'])->create();

        Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->online()->state(['last_map' => 'gm_island'])->create();

        $response = $this->getJson('/api/characters/maps')->assertOk();

        $maps = array_column($response->json('data'), 'map');

        $this->assertContains('prontera', $maps);
        $this->assertNotContains('gm_island', $maps);
        $this->assertSame(1, $response->json('meta.total_online'));
    }

    #[Test]
    public function staff_are_counted_when_the_operator_turns_the_filter_off(): void
    {
        config(['panel.characters.hide_maps_at_or_above_level' => null]);

        Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->online()->state(['last_map' => 'gm_island'])->create();

        $this->getJson('/api/characters/maps')
            ->assertOk()
            ->assertJsonPath('data.0.map', 'gm_island');
    }

    /**
     * SeeHiddenMapStats was declared in the permission map and enforced
     * nowhere. It is the ability somebody answering a report of a player stuck
     * somewhere needs, which is what the legacy used it for.
     */
    #[Test]
    public function staff_with_the_ability_see_hidden_characters_and_staff_maps(): void
    {
        $hidden = Character::factory()->forAccount(Account::factory()->create())->online()
            ->state(['last_map' => 'lighthalzen'])->create();

        DB::connection($this->charMap())->table('cp_charprefs')->insert([
            'char_id' => $hidden->char_id, 'name' => 'HideMapFromWhosOnline', 'value' => '1',
        ]);

        Character::factory()->forAccount(Account::factory()->administrator()->create())
            ->online()->state(['last_map' => 'gm_island'])->create();

        // An ordinary visitor sees neither.
        $this->getJson('/api/characters/maps')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.includes_hidden', false);

        $response = $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->getJson('/api/characters/maps')
            ->assertOk()
            ->assertJsonPath('meta.includes_hidden', true);

        $maps = array_column($response->json('data'), 'map');

        $this->assertContains('lighthalzen', $maps);
        $this->assertContains('gm_island', $maps);
    }

    #[Test]
    public function map_statistics_are_public(): void
    {
        $this->getJson('/api/characters/maps')->assertOk();
    }
}
