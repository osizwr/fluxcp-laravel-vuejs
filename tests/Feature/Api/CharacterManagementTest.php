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
            ->assertJsonCount(1, 'meta.party')
            ->assertJsonPath('meta.party.0.name', 'Leader');
    }

    #[Test]
    public function a_character_in_no_party_has_an_empty_roster(): void
    {
        [, $character] = $this->signedInWithCharacter(['party_id' => 0]);

        $this->getJson("/api/characters/{$character->char_id}")
            ->assertOk()
            ->assertJsonCount(0, 'meta.party');
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

    #[Test]
    public function map_statistics_are_public(): void
    {
        $this->getJson('/api/characters/maps')->assertOk();
    }
}
