<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Importing item descriptions from the client's itemInfo.lua.
 *
 * Covers modules/item/iteminfo.php, which an earlier revision of this port
 * mistook for the filter-vocabulary endpoint and left unported.
 */
final class ItemDescriptionTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    private function asStaff(): self
    {
        return $this->actingAs(Account::factory()->administrator()->create());
    }

    private function upload(string $lua): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('itemInfo.lua', $lua);
    }

    private function stored(int $itemId): ?string
    {
        $value = DB::connection($this->charMap())->table('cp_itemdesc')
            ->where('itemid', $itemId)
            ->value('itemdesc');

        return $value === null ? null : (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_multi_line_description_is_imported(): void
    {
        $lua = <<<'LUA'
        tbl = {
            [501] = {
                identifiedDisplayName = "Red Potion",
                identifiedDescriptionName = {
                    "A potion made from grinded Red Herb.",
                    "Restores 45~65 HP."
                },
                slotCount = 0
            },
        }
        LUA;

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertSame(
            'A potion made from grinded Red Herb.<br />Restores 45~65 HP.',
            $this->stored(501),
        );
    }

    /**
     * Both forms appear in the same real file, so the one-line form has to be
     * recognised before the parser decides it is entering a block.
     */
    #[Test]
    public function a_single_line_description_is_imported(): void
    {
        $lua = <<<'LUA'
        [502] = {
            identifiedDescriptionName = { "Orange Potion.", "Restores 70~100 HP." },
            slotCount = 0
        },
        LUA;

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk();

        $this->assertSame('Orange Potion.<br />Restores 70~100 HP.', $this->stored(502));
    }

    #[Test]
    public function several_items_are_imported_from_one_file(): void
    {
        $lua = <<<'LUA'
        [501] = {
            identifiedDescriptionName = { "First." },
        },
        [502] = {
            identifiedDescriptionName = {
                "Second."
            },
        },
        [503] = {
            identifiedDescriptionName = { "Third." },
        },
        LUA;

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk()
            ->assertJsonPath('data.imported', 3)
            ->assertJsonPath('data.items', 3);

        $this->assertSame('First.', $this->stored(501));
        $this->assertSame('Second.', $this->stored(502));
        $this->assertSame('Third.', $this->stored(503));
    }

    /**
     * The unidentified description sits in the same block and must not be
     * mistaken for the identified one.
     */
    #[Test]
    public function the_unidentified_description_is_not_imported(): void
    {
        $lua = <<<'LUA'
        [501] = {
            unidentifiedDisplayName = "Red Potion",
            unidentifiedDescriptionName = {
                "Unknown item."
            },
            identifiedDescriptionName = {
                "The real description."
            },
        },
        LUA;

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertSame('The real description.', $this->stored(501));
    }

    #[Test]
    public function importing_again_replaces_a_description_rather_than_failing(): void
    {
        $first = '[501] = { identifiedDescriptionName = { "Old text." }, },';
        $second = '[501] = { identifiedDescriptionName = { "New text." }, },';

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($first)])
            ->assertOk();

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($second)])
            ->assertOk()
            ->assertJsonPath('data.items', 1);

        $this->assertSame('New text.', $this->stored(501));
    }

    /**
     * An item the file does not mention keeps the description it has, so a
     * partial file adds rather than wipes.
     */
    #[Test]
    public function an_item_the_file_omits_keeps_its_description(): void
    {
        $this->asStaff()->postJson('/api/admin/items/descriptions', [
            'file' => $this->upload('[501] = { identifiedDescriptionName = { "Kept." }, },'),
        ])->assertOk();

        $this->asStaff()->postJson('/api/admin/items/descriptions', [
            'file' => $this->upload('[502] = { identifiedDescriptionName = { "Added." }, },'),
        ])->assertOk();

        $this->assertSame('Kept.', $this->stored(501));
        $this->assertSame('Added.', $this->stored(502));
    }

    /*
    |--------------------------------------------------------------------------
    | The client's colour markup
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_colour_code_becomes_a_span_and_the_reset_code_closes_it(): void
    {
        // Written as the client writes it: the code, the text, the reset.
        $lua = '[501] = { identifiedDescriptionName = { "Weight: ^7777771^000000 only." }, },';

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk();

        $this->assertSame(
            'Weight: <span style="color:#777777">1</span> only.',
            $this->stored(501),
        );
    }

    #[Test]
    public function an_unclosed_colour_code_is_still_closed_in_the_output(): void
    {
        $lua = '[501] = { identifiedDescriptionName = { "Ends ^ff0000red" }, },';

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk();

        $this->assertSame('Ends <span style="color:#ff0000">red</span>', $this->stored(501));
    }

    /**
     * The one place this port stores HTML, so the escaping is the thing that
     * makes it safe. Every character of the item's own text is escaped before
     * any markup is added, and the only markup added is built from six matched
     * hex digits.
     */
    #[Test]
    public function markup_in_the_file_is_escaped_rather_than_stored_as_html(): void
    {
        $lua = '[501] = { identifiedDescriptionName = { "<script>alert(1)</script> & \"quoted\"" }, },';

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk();

        $stored = (string) $this->stored(501);

        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringContainsString('&lt;script&gt;', $stored);
        $this->assertStringContainsString('&amp;', $stored);
    }

    #[Test]
    public function a_colour_code_cannot_inject_a_style_or_an_attribute(): void
    {
        // Not six hex digits, so not a colour code -- it stays text.
        $lua = '[501] = { identifiedDescriptionName = { "^zzzzzz^00ff00ok" }, },';

        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', ['file' => $this->upload($lua)])
            ->assertOk();

        $stored = (string) $this->stored(501);

        $this->assertSame('^zzzzzz<span style="color:#00ff00">ok</span>', $stored);
    }

    /*
    |--------------------------------------------------------------------------
    | The endpoints
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_file_with_no_descriptions_is_refused(): void
    {
        $this->asStaff()
            ->postJson('/api/admin/items/descriptions', [
                'file' => $this->upload("-- nothing useful here\nlocal x = 1\n"),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, DB::connection($this->charMap())->table('cp_itemdesc')->count());
    }

    #[Test]
    public function the_count_is_reported(): void
    {
        $this->asStaff()->postJson('/api/admin/items/descriptions', [
            'file' => $this->upload('[501] = { identifiedDescriptionName = { "One." }, },'),
        ])->assertOk();

        $this->asStaff()
            ->getJson('/api/admin/items/descriptions')
            ->assertOk()
            ->assertJsonPath('data.items', 1);
    }

    #[Test]
    public function stored_descriptions_can_be_cleared(): void
    {
        $this->asStaff()->postJson('/api/admin/items/descriptions', [
            'file' => $this->upload('[501] = { identifiedDescriptionName = { "One." }, },'),
        ])->assertOk();

        $this->asStaff()
            ->deleteJson('/api/admin/items/descriptions')
            ->assertOk()
            ->assertJsonPath('data.removed', 1);

        $this->assertNull($this->stored(501));
    }

    #[Test]
    public function a_player_cannot_import_descriptions(): void
    {
        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/items/descriptions', [
                'file' => $this->upload('[501] = { identifiedDescriptionName = { "No." }, },'),
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function a_guest_cannot_import_descriptions(): void
    {
        $this->postJson('/api/admin/items/descriptions', [
            'file' => $this->upload('[501] = { identifiedDescriptionName = { "No." }, },'),
        ])->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading it back on an item's page
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_items_page_shows_its_imported_description(): void
    {
        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => 501, 'name_aegis' => 'Red_Potion', 'name_english' => 'Red Potion',
            'type' => 'Healing',
        ]);

        $this->asStaff()->postJson('/api/admin/items/descriptions', [
            'file' => $this->upload('[501] = { identifiedDescriptionName = { "Restores HP." }, },'),
        ])->assertOk();

        $this->getJson('/api/items/501')
            ->assertOk()
            ->assertJsonPath('data.description', 'Restores HP.');
    }

    #[Test]
    public function an_item_with_no_description_reports_null(): void
    {
        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => 909, 'name_aegis' => 'Jellopy', 'name_english' => 'Jellopy', 'type' => 'Etc',
        ]);

        $this->getJson('/api/items/909')
            ->assertOk()
            ->assertJsonPath('data.description', null);
    }

    #[Test]
    public function the_description_is_withheld_when_the_operator_turns_it_off(): void
    {
        config(['panel.items.show_descriptions' => false]);

        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => 501, 'name_aegis' => 'Red_Potion', 'name_english' => 'Red Potion',
            'type' => 'Healing',
        ]);

        DB::connection($this->charMap())->table('cp_itemdesc')->insert([
            'itemid' => 501, 'itemdesc' => 'Restores HP.',
        ]);

        $this->getJson('/api/items/501')
            ->assertOk()
            ->assertJsonMissingPath('data.description');
    }

    #[Test]
    public function the_listing_does_not_carry_descriptions(): void
    {
        DB::connection($this->charMap())->table('item_db_re')->insert([
            'id' => 501, 'name_aegis' => 'Red_Potion', 'name_english' => 'Red Potion',
            'type' => 'Healing',
        ]);

        DB::connection($this->charMap())->table('cp_itemdesc')->insert([
            'itemid' => 501, 'itemdesc' => 'Restores HP.',
        ]);

        // One query per row would make the listing the most expensive page in
        // the application.
        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonMissingPath('data.0.description');
    }
}
