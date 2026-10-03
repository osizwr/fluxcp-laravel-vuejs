<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\NewsArticle;
use App\Services\Content\ContentRenderer;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The news editor and the static-page CMS.
 *
 * Ports the coverage for news/manage, add, edit, delete and pages/index,
 * content, add, edit, delete.
 *
 * The escaping tests are the important ones. FluxCP echoed both body columns
 * straight into the page, so the editor was a stored-XSS vector reachable by
 * every administrator — one careless or compromised staff account reaching
 * every player who loads the front page.
 */
final class ContentManagementTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private function loginConnection(): string
    {
        return $this->app->make(ServerRegistry::class)->current()->loginConnection();
    }

    private function asAdmin(): Account
    {
        $admin = Account::factory()->administrator()->named('editor')->create();

        $this->actingAs($admin);

        return $admin;
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_script_tag_in_a_body_is_stripped(): void
    {
        $renderer = $this->app->make(ContentRenderer::class);

        foreach ([
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<iframe src="https://evil.test"></iframe>',
            '<a href="javascript:alert(1)">click</a>',
            '<div onclick="alert(1)">x</div>',
        ] as $payload) {
            $html = $renderer->toHtml($payload);

            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('onerror', $html);
            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringNotContainsString('<iframe', $html);
            $this->assertStringNotContainsString('javascript:', $html);
        }
    }

    #[Test]
    public function a_markdown_link_cannot_carry_a_script_url(): void
    {
        // With HTML stripped, Markdown's own link syntax is the remaining
        // vector, which is why unsafe links are disallowed too.
        $html = $this->app->make(ContentRenderer::class)->toHtml('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('click', $html);
    }

    #[Test]
    public function ordinary_markdown_still_renders(): void
    {
        $html = $this->app->make(ContentRenderer::class)
            ->toHtml("## Patch notes\n\n- One **important** change\n\n[Read more](https://example.test)");

        $this->assertStringContainsString('<h2>', $html);
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | News
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_news_editor_is_administrator_only(): void
    {
        $this->getJson('/api/news/manage')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/news/manage')->assertStatus(403);

        $this->actingAs(Account::factory()->juniorGameMaster()->create())
            ->postJson('/api/news', ['title' => 'x', 'body' => 'y'])->assertStatus(403);
    }

    #[Test]
    public function an_article_can_be_published(): void
    {
        $admin = $this->asAdmin();

        $this->postJson('/api/news', [
            'title' => 'Server maintenance',
            'body' => "## Downtime\n\nWe will be **offline** on Friday.",
        ])->assertCreated();

        $this->assertDatabaseHas('cp_cmsnews', [
            'title' => 'Server maintenance',
            // The byline defaults to whoever wrote it rather than free text.
            'author' => $admin->userid,
        ], $this->loginConnection());
    }

    #[Test]
    public function a_published_article_renders_safely_on_the_public_view(): void
    {
        $this->asAdmin();

        $this->postJson('/api/news', [
            'title' => 'Hello',
            'body' => "Welcome!<script>alert(document.cookie)</script>\n\n**Have fun.**",
        ])->assertCreated();

        $this->postJson('/api/auth/logout');

        $id = DB::connection($this->loginConnection())->table('cp_cmsnews')->value('id');

        $response = $this->getJson("/api/news/{$id}")->assertOk();

        // The raw body is preserved for the editor...
        $this->assertStringContainsString('<script>', $response->json('data.body'));

        // ...and the rendered form has it removed.
        $this->assertStringNotContainsString('<script', $response->json('data.body_html'));
        $this->assertStringContainsString('<strong>Have fun.</strong>', $response->json('data.body_html'));
    }

    #[Test]
    public function the_listing_does_not_carry_rendered_bodies(): void
    {
        // Twenty articles would otherwise ship twenty rich-text documents to
        // render three lines of each.
        NewsArticle::factory()->count(3)->create();

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonMissingPath('data.0.body_html');
    }

    #[Test]
    public function an_article_can_be_edited_without_republishing_it(): void
    {
        /*
         * `created` orders the front page, so moving it on every edit would
         * push an old article back to the top for a typo fix.
         */
        $this->asAdmin();

        $article = NewsArticle::factory()->titled('Original')
            ->publishedAt('2026-01-01 10:00:00')->create();

        $this->putJson("/api/news/{$article->id}", [
            'title' => 'Corrected',
            'body' => 'Fixed a typo.',
        ])->assertOk();

        $row = DB::connection($this->loginConnection())->table('cp_cmsnews')
            ->where('id', $article->id)->first();

        $this->assertSame('Corrected', $row->title);
        $this->assertStringStartsWith('2026-01-01', (string) $row->created);
        $this->assertNotSame((string) $row->created, (string) $row->modified);
    }

    #[Test]
    public function a_read_more_link_must_be_http(): void
    {
        // The listing renders this as a link, so a javascript: URL here is the
        // same vector by another route.
        $this->asAdmin();

        $this->postJson('/api/news', [
            'title' => 'x', 'body' => 'y', 'link' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors('link');

        $this->postJson('/api/news', [
            'title' => 'x', 'body' => 'y', 'link' => 'https://example.test/post',
        ])->assertCreated();
    }

    #[Test]
    public function an_article_can_be_deleted(): void
    {
        $this->asAdmin();

        $article = NewsArticle::factory()->create();

        $this->deleteJson("/api/news/{$article->id}")->assertOk();

        $this->assertDatabaseMissing('cp_cmsnews', ['id' => $article->id], $this->loginConnection());
    }

    #[Test]
    public function the_management_listing_paginates_and_sorts(): void
    {
        $this->asAdmin();

        NewsArticle::factory()->count(12)->create();

        $this->getJson('/api/news/manage?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 12);

        $this->getJson('/api/news/manage?sort=title&direction=asc')->assertOk();
        $this->getJson('/api/news/manage?sort=body')->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Static pages
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_page_can_be_created_and_read_publicly(): void
    {
        $this->asAdmin();

        $this->postJson('/api/pages', [
            'path' => 'rules',
            'title' => 'Server rules',
            'body' => "## Be decent\n\nNo botting.",
        ])->assertCreated();

        $this->postJson('/api/auth/logout');

        $this->getJson('/api/pages/rules')
            ->assertOk()
            ->assertJsonPath('data.title', 'Server rules')
            ->assertJsonPath('data.path', 'rules');
    }

    #[Test]
    public function a_page_path_is_normalised(): void
    {
        /*
         * The legacy stored whatever was typed and looked it up with an exact
         * match, so a page whose link and row disagreed about a slash or a
         * capital was simply unreachable.
         */
        $this->asAdmin();

        $this->postJson('/api/pages', [
            'path' => 'Rules/', 'title' => 'Rules', 'body' => 'x',
        ])->assertCreated();

        $this->assertDatabaseHas('cp_cmspages', ['path' => 'rules'], $this->loginConnection());

        $this->getJson('/api/pages/rules')->assertOk();
        $this->getJson('/api/pages/Rules')->assertOk();
    }

    #[Test]
    public function two_pages_cannot_share_a_path(): void
    {
        $this->asAdmin();

        $this->postJson('/api/pages', ['path' => 'rules', 'title' => 'A', 'body' => 'x'])
            ->assertCreated();

        $this->postJson('/api/pages', ['path' => 'Rules', 'title' => 'B', 'body' => 'y'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');
    }

    #[Test]
    public function a_path_that_could_be_a_traversal_is_refused(): void
    {
        $this->asAdmin();

        foreach (['../etc/passwd', 'rules?x=1', 'rules#anchor', '/leading', 'a b', '<script>'] as $path) {
            $this->postJson('/api/pages', ['path' => $path, 'title' => 'x', 'body' => 'y'])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('cp_cmspages', 0, $this->loginConnection());
    }

    #[Test]
    public function a_nested_path_is_allowed(): void
    {
        $this->asAdmin();

        $this->postJson('/api/pages', [
            'path' => 'help/getting-started', 'title' => 'Getting started', 'body' => 'x',
        ])->assertCreated();

        $this->getJson('/api/pages/help/getting-started')
            ->assertOk()
            ->assertJsonPath('data.title', 'Getting started');
    }

    #[Test]
    public function a_page_can_be_edited_and_deleted(): void
    {
        $this->asAdmin();

        $this->postJson('/api/pages', ['path' => 'rules', 'title' => 'Rules', 'body' => 'x'])
            ->assertCreated();

        $id = DB::connection($this->loginConnection())->table('cp_cmspages')->value('id');

        $this->putJson("/api/pages/{$id}", [
            'path' => 'rules', 'title' => 'Server rules', 'body' => 'Updated.',
        ])->assertOk();

        $this->assertDatabaseHas('cp_cmspages', ['title' => 'Server rules'], $this->loginConnection());

        $this->deleteJson("/api/pages/{$id}")->assertOk();

        $this->getJson('/api/pages/rules')->assertNotFound();
    }

    #[Test]
    public function the_page_listing_is_administrator_only_but_reading_one_is_not(): void
    {
        $this->asAdmin();
        $this->postJson('/api/pages', ['path' => 'rules', 'title' => 'Rules', 'body' => 'x'])
            ->assertCreated();
        $this->postJson('/api/auth/logout');

        $this->getJson('/api/pages')->assertStatus(401);
        $this->getJson('/api/pages/rules')->assertOk();
    }

    #[Test]
    public function the_page_listing_omits_bodies(): void
    {
        $this->asAdmin();

        $this->postJson('/api/pages', [
            'path' => 'rules', 'title' => 'Rules', 'body' => str_repeat('long ', 500),
        ])->assertCreated();

        $this->getJson('/api/pages')
            ->assertOk()
            ->assertJsonMissingPath('data.0.body');
    }

    #[Test]
    public function an_unknown_page_is_a_404(): void
    {
        $this->getJson('/api/pages/nonexistent')->assertNotFound();
    }
}
