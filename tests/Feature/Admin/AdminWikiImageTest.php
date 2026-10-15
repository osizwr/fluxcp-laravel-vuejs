<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Images dropped into the wiki editor, and the preview beside it.
 *
 * The upload is the only place in this panel where a file an outsider could
 * influence is written to disk, so most of these are about what it refuses.
 * The two that matter most are the pair at the bottom: a PHP file wearing a
 * `.png` is not an image, and the name a file arrives under never reaches the
 * filesystem.
 */
final class AdminWikiImageTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'wiki.enabled' => true,
            'wiki.uploads.disk' => 'public',
            'wiki.uploads.directory' => 'wiki',
            'wiki.uploads.section_image.directory' => 'wiki/sections',
            'wiki.uploads.section_image.width' => 120,
            'wiki.uploads.section_image.height' => 120,
            'wiki.uploads.section_image.max_kilobytes' => 2048,
            'wiki.uploads.section_image.extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            'wiki.uploads.section_image.quality' => 82,
            'wiki.uploads.max_kilobytes' => 64,
            'wiki.uploads.extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'wiki.uploads.max_width' => 200,
            'wiki.uploads.max_height' => 200,
        ]);
    }

    private function admin(): Account
    {
        return Account::factory()->administrator()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Reaching it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function uploading_is_administrator_only(): void
    {
        $image = UploadedFile::fake()->image('shot.png', 100, 100);

        $this->postJson('/api/admin/wiki/images', ['image' => $image])->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/wiki/images', ['image' => $image])->assertStatus(403);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->postJson('/api/admin/wiki/images', ['image' => $image])->assertStatus(403);

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    #[Test]
    public function previewing_is_administrator_only(): void
    {
        $this->postJson('/api/admin/wiki/preview', ['body' => '# Hi'])->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/wiki/preview', ['body' => '# Hi'])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Uploading
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_stores_an_image_and_answers_with_its_url(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                'image' => UploadedFile::fake()->image('patcher window.png', 120, 80),
            ])
            ->assertStatus(201);

        $url = $response->json('data.url');

        $this->assertIsString($url);
        $this->assertStringContainsString('/wiki/', $url);

        // Stored under the hash of its contents, with the extension the file
        // was detected to be rather than the one it was called.
        $stored = Storage::disk('public')->files('wiki');

        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression('/^wiki\/[0-9a-f]{32}\.(png|jpg)$/', $stored[0]);
    }

    /**
     * What is written into the page is a path, not a URL with a host on it.
     *
     * The body is a Markdown file that outlives the domain in front of it:
     * moving to HTTPS or to a new hostname must not leave every page full of
     * images pointing at the old one.
     */
    #[Test]
    public function the_url_written_into_the_page_has_no_host_on_it(): void
    {
        config(['app.url' => 'https://panel.example.test']);

        $url = (string) $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                'image' => UploadedFile::fake()->image('shot.png', 40, 40),
            ])
            ->assertStatus(201)
            ->json('data.url');

        $this->assertStringStartsWith('/', $url);
        $this->assertStringNotContainsString('panel.example.test', $url);
    }

    /**
     * The name the browser sent is a caption, never a path.
     *
     * The file arrives called `patcher window.png` and the editor is handed
     * "patcher window" to write between the brackets -- while the thing on
     * disk is named after its own bytes.
     */
    #[Test]
    public function the_uploaded_name_becomes_alt_text_and_nothing_else(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                'image' => UploadedFile::fake()->image('patcher window.png', 40, 40),
            ])
            ->assertStatus(201);

        $this->assertSame('patcher window', $response->json('data.alt'));
        $this->assertStringNotContainsString('patcher', (string) $response->json('data.url'));
    }

    /**
     * Brackets in a filename would end the Markdown early.
     *
     * `![a ](url)](x)` is not the link somebody meant to write, and the stray
     * half of it renders as text in the middle of the page.
     */
    #[Test]
    public function alt_text_cannot_break_out_of_the_markdown(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                'image' => UploadedFile::fake()->image('a](x)b.png', 40, 40),
            ])
            ->assertStatus(201);

        $alt = (string) $response->json('data.alt');

        $this->assertStringNotContainsString(']', $alt);
        $this->assertStringNotContainsString('(', $alt);
    }

    #[Test]
    public function the_same_image_twice_is_one_file(): void
    {
        $admin = $this->admin();

        $first = $this->actingAs($admin)->postJson('/api/admin/wiki/images', [
            'image' => UploadedFile::fake()->image('one.png', 60, 60),
        ])->assertStatus(201);

        /*
         * The same bytes under a different name. UploadedFile::fake() draws a
         * blank image of the given size, so two of the same size are byte for
         * byte the same file -- which is exactly the case being asserted.
         */
        $second = $this->actingAs($admin)->postJson('/api/admin/wiki/images', [
            'image' => UploadedFile::fake()->image('two.png', 60, 60),
        ])->assertStatus(201);

        $this->assertSame($first->json('data.url'), $second->json('data.url'));
        $this->assertCount(1, Storage::disk('public')->files('wiki'));
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_a_file_that_is_not_an_image(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    /**
     * A script with an image's extension.
     *
     * The classic upload hole: `shell.php.png` passes anything that looks only
     * at the last extension. It is refused here because the file is also asked
     * to decode as a picture, and this one does not.
     */
    #[Test]
    public function it_refuses_a_script_wearing_an_image_extension(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'shell.php.png',
            "<?php echo shell_exec(\$_GET['c']); ?>",
        );

        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    /**
     * SVG is left out on purpose, so it is asserted on purpose.
     *
     * It is an image to a browser and a document to a sanitiser: it can carry
     * script, and this panel never serves operator-authored markup.
     */
    #[Test]
    public function it_refuses_an_svg(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    #[Test]
    public function it_refuses_an_image_past_the_pixel_cap(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                // Past the 200px cap this test configures, and no larger:
                // asking the fake for a genuinely huge image allocates it.
                'image' => UploadedFile::fake()->image('huge.png', 400, 400),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    /**
     * A real image that reports a size past the cap.
     *
     * Drawn rather than faked as bytes, so what the rule rejects it for is
     * its size: a non-image would have been refused one rule earlier and the
     * test would pass without the cap existing.
     */
    #[Test]
    public function it_refuses_an_image_past_the_size_cap(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/images', [
                'image' => UploadedFile::fake()->image('big.png', 150, 150)->size(2048),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    /*
    |--------------------------------------------------------------------------
    | A section's art
    |--------------------------------------------------------------------------
    |
    | The square on a tile on /wiki. Exactly one size, any format in, WebP out.
    */

    #[Test]
    public function uploading_a_section_image_is_administrator_only(): void
    {
        $square = UploadedFile::fake()->image('tile.png', 120, 120);

        $this->postJson('/api/admin/wiki/sections/image', ['image' => $square])->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/wiki/sections/image', ['image' => $square])->assertStatus(403);

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    /**
     * Whatever goes in, WebP comes out.
     *
     * The conversion is the point: nine of these load on the index at once,
     * on a page that is frequently somebody's first, and WebP is a fraction
     * of the PNG an operator will be exporting.
     */
    #[Test]
    public function a_section_image_is_converted_to_webp(): void
    {
        $url = (string) $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/sections/image', [
                'image' => UploadedFile::fake()->image('tile.png', 120, 120),
            ])
            ->assertStatus(201)
            ->json('data.url');

        $this->assertStringEndsWith('.webp', $url);

        $stored = Storage::disk('public')->files('wiki/sections');

        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression('/^wiki\/sections\/[0-9a-f]{32}\.webp$/', $stored[0]);

        // Not merely renamed: the bytes on disk are a WebP file, which begins
        // RIFF....WEBP.
        $bytes = (string) Storage::disk('public')->get($stored[0]);

        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));
    }

    #[Test]
    public function a_section_image_accepts_any_format_it_can_decode(): void
    {
        foreach (['tile.jpg', 'tile.gif', 'tile.webp'] as $name) {
            $this->actingAs($this->admin())
                ->postJson('/api/admin/wiki/sections/image', [
                    'image' => UploadedFile::fake()->image($name, 120, 120),
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.url', fn (string $url): bool => str_ends_with($url, '.webp'));
        }
    }

    /**
     * The one rule that is exact rather than a maximum.
     *
     * A tile that has to be cropped or squashed to fit is a decision about
     * somebody's artwork taken by a program that cannot see it.
     */
    #[Test]
    public function a_section_image_must_be_the_exact_square(): void
    {
        foreach ([[119, 120], [120, 119], [240, 240], [120, 240]] as [$width, $height]) {
            $this->actingAs($this->admin())
                ->postJson('/api/admin/wiki/sections/image', [
                    'image' => UploadedFile::fake()->image('tile.png', $width, $height),
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('image');
        }

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    #[Test]
    public function a_section_image_refuses_an_svg(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'tile.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/sections/image', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    /*
    |--------------------------------------------------------------------------
    | The preview
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_preview_renders_the_body_as_markdown(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/preview', ['body' => "## Getting in\n\nDownload it."])
            ->assertOk()
            ->assertJsonPath('data.html', fn (string $html): bool => str_contains($html, '<h2>')
                && str_contains($html, 'Download it.'));
    }

    /**
     * The preview is the published page, which means it strips what that
     * strips. A preview that showed the script running would be telling the
     * author their page does something it will not do.
     */
    #[Test]
    public function the_preview_strips_what_the_published_page_strips(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/preview', [
                'body' => "Hello <script>alert(1)</script>\n\n[x](javascript:alert(1))",
            ])
            ->assertOk()
            ->json('data.html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function an_empty_body_previews_as_nothing(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/preview', ['body' => ''])
            ->assertOk()
            ->assertJsonPath('data.html', '');
    }
}
