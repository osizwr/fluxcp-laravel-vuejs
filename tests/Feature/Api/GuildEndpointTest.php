<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Character;
use App\Models\Guild;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The guild endpoints.
 *
 * Ports the coverage for modules/guild/index.php, view.php, emblem.php and
 * export.php.
 */
final class GuildEndpointTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function charMap(): string
    {
        return $this->app->make(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }

    private function asStaff(): self
    {
        return $this->actingAs(Account::factory()->administrator()->create());
    }

    /**
     * A 24-bit BMP, gzip-compressed and hex-encoded exactly as rAthena's char
     * server writes it: `StringBuf_Printf("%02x", ...)` per byte into a blob.
     */
    private function emblemHex(int $width = 4, int $height = 4, array $transparentAt = []): string
    {
        $image = imagecreatetruecolor($width, $height);

        $blue = imagecolorallocate($image, 0, 0, 255);
        $magenta = imagecolorallocate($image, 255, 0, 255);

        imagefill($image, 0, 0, $blue);

        foreach ($transparentAt as [$x, $y]) {
            imagesetpixel($image, $x, $y, $magenta);
        }

        ob_start();
        imagebmp($image, null, false);
        $bmp = (string) ob_get_clean();

        imagedestroy($image);

        return bin2hex(gzcompress($bmp));
    }

    /*
    |--------------------------------------------------------------------------
    | Directory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_directory_is_staff_only(): void
    {
        // JuniorGameMaster in the legacy access map, preserved.
        $this->getJson('/api/guilds')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/guilds')
            ->assertStatus(403);
    }

    #[Test]
    public function the_directory_lists_guilds_with_their_member_counts(): void
    {
        $guild = Guild::factory()->named('Valkyrie')->state(['guild_lv' => 20])->create();

        Character::factory()->forAccount(Account::factory()->create())->count(3)
            ->state(['guild_id' => $guild->guild_id])->create();

        $this->asStaff()
            ->getJson('/api/guilds')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Valkyrie')
            ->assertJsonPath('data.0.members', 3);
    }

    #[Test]
    public function the_directory_can_be_searched_and_sorted(): void
    {
        Guild::factory()->named('Alpha')->state(['guild_lv' => 1])->create();
        Guild::factory()->named('Omega')->state(['guild_lv' => 50])->create();

        $this->asStaff()
            ->getJson('/api/guilds?name=Ome')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Omega');

        $this->asStaff()
            ->getJson('/api/guilds?sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha');
    }

    #[Test]
    public function an_unlisted_sort_column_is_refused(): void
    {
        Guild::factory()->create();

        $this->asStaff()
            ->getJson('/api/guilds?sort=emblem_data')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    /*
    |--------------------------------------------------------------------------
    | Detail
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guild_page_lists_members_allies_and_enemies(): void
    {
        $guild = Guild::factory()->named('Valkyrie')->create();
        $ally = Guild::factory()->named('Friends')->create();
        $enemy = Guild::factory()->named('Rivals')->create();

        $member = Character::factory()->forAccount(Account::factory()->create())
            ->named('Leader')->state(['guild_id' => $guild->guild_id])->create();

        DB::connection($this->charMap())->table('guild_member')->insert([
            'guild_id' => $guild->guild_id, 'char_id' => $member->char_id, 'exp' => 0,
        ]);

        DB::connection($this->charMap())->table('guild_alliance')->insert([
            ['guild_id' => $guild->guild_id, 'alliance_id' => $ally->guild_id, 'opposition' => 0, 'name' => 'Friends'],
            ['guild_id' => $guild->guild_id, 'alliance_id' => $enemy->guild_id, 'opposition' => 1, 'name' => 'Rivals'],
        ]);

        DB::connection($this->charMap())->table('guild_castle')->insert([
            ['castle_id' => 7, 'guild_id' => $guild->guild_id],
        ]);

        $this->actingAs(Account::factory()->create())
            ->getJson("/api/guilds/{$guild->guild_id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Valkyrie')
            ->assertJsonPath('data.members.0.name', 'Leader')
            ->assertJsonPath('data.allies.0.name', 'Friends')
            ->assertJsonPath('data.enemies.0.name', 'Rivals')
            ->assertJsonPath('data.castles.0', 7);
    }

    #[Test]
    public function a_characters_queued_for_deletion_is_not_listed_as_a_member(): void
    {
        $guild = Guild::factory()->create();
        $account = Account::factory()->create();

        Character::factory()->forAccount($account)->named('Active')
            ->state(['guild_id' => $guild->guild_id])->create();
        Character::factory()->forAccount($account)->named('Leaving')->pendingDeletion()
            ->state(['guild_id' => $guild->guild_id])->create();

        $this->actingAs($account)
            ->getJson("/api/guilds/{$guild->guild_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.name', 'Active');
    }

    #[Test]
    public function an_unknown_guild_is_a_404(): void
    {
        $this->actingAs(Account::factory()->create())
            ->getJson('/api/guilds/999999')
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_view_a_guild(): void
    {
        $guild = Guild::factory()->create();

        $this->getJson("/api/guilds/{$guild->guild_id}")->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Emblem
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_emblem_is_decoded_to_png(): void
    {
        $guild = Guild::factory()->create();

        $hex = $this->emblemHex();

        DB::connection($this->charMap())->table('guild')
            ->where('guild_id', $guild->guild_id)
            ->update(['emblem_len' => strlen($hex) / 2, 'emblem_data' => $hex, 'emblem_id' => 1]);

        $response = $this->get("/api/guilds/{$guild->guild_id}/emblem")->assertOk();

        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($response->getContent(), 0, 8));
    }

    #[Test]
    public function magenta_becomes_transparent(): void
    {
        /*
         * The RO client renders pure magenta as transparent. BMP has no alpha,
         * so without translating it every emblem shows a bright pink
         * background on every page that displays one.
         */
        $guild = Guild::factory()->create();

        $hex = $this->emblemHex(transparentAt: [[0, 0]]);

        DB::connection($this->charMap())->table('guild')
            ->where('guild_id', $guild->guild_id)
            ->update(['emblem_len' => strlen($hex) / 2, 'emblem_data' => $hex, 'emblem_id' => 1]);

        $png = $this->get("/api/guilds/{$guild->guild_id}/emblem")->assertOk()->getContent();

        $image = imagecreatefromstring($png);

        $this->assertNotFalse($image);
        $this->assertSame(
            127,
            (imagecolorat($image, 0, 0) >> 24) & 0x7F,
            'The magenta pixel must be fully transparent.',
        );
        $this->assertSame(
            0,
            (imagecolorat($image, 1, 1) >> 24) & 0x7F,
            'A non-magenta pixel must stay opaque.',
        );
    }

    #[Test]
    public function a_guild_with_no_emblem_is_a_404(): void
    {
        // Not a placeholder behind a 200, which a cache could not tell apart
        // from a real emblem.
        $guild = Guild::factory()->create();

        $this->get("/api/guilds/{$guild->guild_id}/emblem")->assertNotFound();
    }

    #[Test]
    public function malformed_emblem_data_is_a_404_rather_than_an_error(): void
    {
        /*
         * The column holds whatever the game client uploaded, so corrupt data
         * is routine. A broken emblem must not break the page listing it.
         */
        $guild = Guild::factory()->create();

        foreach (['not-hex-at-all', 'abcdef', '', str_repeat('ff', 50)] as $garbage) {
            DB::connection($this->charMap())->table('guild')
                ->where('guild_id', $guild->guild_id)
                ->update(['emblem_len' => 10, 'emblem_data' => $garbage, 'emblem_id' => 1]);

            Cache::flush();

            $this->get("/api/guilds/{$guild->guild_id}/emblem")
                ->assertNotFound();
        }
    }

    #[Test]
    public function emblems_can_be_turned_off(): void
    {
        config()->set('panel.guilds.emblems', false);

        $guild = Guild::factory()->create();

        $this->get("/api/guilds/{$guild->guild_id}/emblem")->assertNotFound();
    }

    #[Test]
    public function the_emblem_is_public(): void
    {
        // ANYONE in the legacy access map: emblems appear beside character
        // names on public ladders.
        $guild = Guild::factory()->create();

        $this->get("/api/guilds/{$guild->guild_id}/emblem")->assertNotFound();
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_roster_exports_as_csv(): void
    {
        $guild = Guild::factory()->named('Valkyrie')->create();

        Character::factory()->forAccount(Account::factory()->create())->named('Leader')
            ->state(['guild_id' => $guild->guild_id, 'base_level' => 99])->create();

        $response = $this->asStaff()->get("/api/guilds/{$guild->guild_id}/members.csv");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Character,Level', $csv);
        $this->assertStringContainsString('Leader,99', $csv);
    }

    #[Test]
    public function the_export_is_staff_only(): void
    {
        $guild = Guild::factory()->create();

        $this->actingAs(Account::factory()->create())
            ->get("/api/guilds/{$guild->guild_id}/members.csv")
            ->assertStatus(403);
    }

    #[Test]
    public function exporting_an_empty_guild_is_a_404(): void
    {
        $guild = Guild::factory()->create();

        $this->asStaff()->get("/api/guilds/{$guild->guild_id}/members.csv")->assertNotFound();
    }
}
