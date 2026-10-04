<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\NewsArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * News taken from an external feed.
 *
 * Covers FluxCP's `CMSNewsType = 2`, which an earlier revision of this port
 * did not have: news could only come from the panel's own table.
 */
final class NewsFeedTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const URL = 'https://forum.example.test/rss';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'panel.news.source' => 'feed',
            'panel.news.feed_url' => self::URL,
        ]);
    }

    private function rss(string $items): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">
            <channel>
                <title>Announcements</title>
                {$items}
            </channel>
        </rss>
        XML;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a feed
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function news_comes_from_the_feed_when_one_is_configured(): void
    {
        Http::fake([self::URL => Http::response($this->rss(<<<'XML'
            <item>
                <title>Server maintenance</title>
                <description>We will be down for an hour.</description>
                <link>https://forum.example.test/t/1</link>
                <pubDate>Mon, 15 Sep 2025 10:00:00 +0000</pubDate>
                <dc:creator>GameMaster</dc:creator>
            </item>
        XML))]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('meta.source', 'feed')
            ->assertJsonPath('data.0.title', 'Server maintenance')
            ->assertJsonPath('data.0.excerpt', 'We will be down for an hour.')
            ->assertJsonPath('data.0.link', 'https://forum.example.test/t/1')
            ->assertJsonPath('data.0.author', 'GameMaster')
            ->assertJsonPath('data.0.external', true);
    }

    #[Test]
    public function an_atom_feed_is_read_as_well_as_rss(): void
    {
        Http::fake([self::URL => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
            <title>Announcements</title>
            <entry>
                <title>New episode</title>
                <summary>Episode 17 is live.</summary>
                <link href="https://forum.example.test/t/2"/>
                <published>2025-09-15T10:00:00Z</published>
                <author><name>Staff</name></author>
            </entry>
        </feed>
        XML)]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'New episode')
            ->assertJsonPath('data.0.excerpt', 'Episode 17 is live.')
            ->assertJsonPath('data.0.link', 'https://forum.example.test/t/2')
            ->assertJsonPath('data.0.author', 'Staff');
    }

    #[Test]
    public function panel_news_is_served_when_no_feed_is_configured(): void
    {
        config(['panel.news.source' => 'panel']);

        NewsArticle::factory()->create(['title' => 'Written here']);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('meta.source', 'panel')
            ->assertJsonPath('data.0.title', 'Written here');
    }

    /**
     * A feed URL that is set but unusable must not silently become a file
     * read, so the panel falls back to its own table.
     */
    #[Test]
    public function a_non_http_feed_url_is_ignored(): void
    {
        config(['panel.news.feed_url' => 'file:///etc/passwd']);

        NewsArticle::factory()->create(['title' => 'Written here']);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('meta.source', 'panel')
            ->assertJsonPath('data.0.title', 'Written here');
    }

    #[Test]
    public function the_feed_limit_is_honoured(): void
    {
        $items = '';

        for ($i = 1; $i <= 10; $i++) {
            $items .= "<item><title>Item {$i}</title><description>d</description></item>";
        }

        Http::fake([self::URL => Http::response($this->rss($items))]);

        $this->getJson('/api/news?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Untrusted content
    |--------------------------------------------------------------------------
    |
    | The feed is somebody else's content arriving over the network, and a
    | description is HTML by specification. Rendering it would hand whoever
    | runs that forum the ability to put markup on this panel's front page.
    |
    */

    #[Test]
    public function markup_in_the_feed_is_stripped_rather_than_passed_through(): void
    {
        Http::fake([self::URL => Http::response($this->rss(<<<'XML'
            <item>
                <title>Patch &lt;b&gt;notes&lt;/b&gt;</title>
                <description>&lt;script&gt;alert(1)&lt;/script&gt;Real text.</description>
            </item>
        XML))]);

        $response = $this->getJson('/api/news')->assertOk();

        $this->assertSame('Patch notes', $response->json('data.0.title'));
        $this->assertSame('Real text.', $response->json('data.0.excerpt'));
    }

    /**
     * strip_tags() takes the tags off and leaves what was between them, so a
     * script element's body would otherwise appear as visible text.
     */
    #[Test]
    public function an_unclosed_script_element_does_not_leak_its_body(): void
    {
        Http::fake([self::URL => Http::response($this->rss(
            '<item><title>T</title>'
            .'<description>Before &lt;script&gt;leaked()</description></item>',
        ))]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('data.0.excerpt', 'Before');
    }

    #[Test]
    public function a_cdata_description_is_read_and_stripped(): void
    {
        Http::fake([self::URL => Http::response($this->rss(
            '<item><title>T</title><description><![CDATA[<p>Inside <em>CDATA</em>.</p>]]></description></item>',
        ))]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('data.0.excerpt', 'Inside CDATA.');
    }

    #[Test]
    public function a_javascript_link_is_dropped(): void
    {
        Http::fake([self::URL => Http::response($this->rss(
            '<item><title>T</title><description>d</description>'
            .'<link>javascript:alert(1)</link></item>',
        ))]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('data.0.link', null);
    }

    /*
    |--------------------------------------------------------------------------
    | When the feed misbehaves
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unreachable_feed_serves_the_last_good_result(): void
    {
        Http::fake([self::URL => Http::response($this->rss(
            '<item><title>Cached headline</title><description>d</description></item>',
        ))]);

        $this->getJson('/api/news')->assertOk()->assertJsonPath('data.0.title', 'Cached headline');

        // The cache entry expires, and by then the forum is down.
        config(['panel.news.feed_cache_seconds' => 0]);
        Http::fake([self::URL => Http::response('', 503)]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Cached headline');
    }

    #[Test]
    public function a_feed_that_has_never_worked_yields_an_empty_list_not_an_error(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function unparseable_xml_yields_an_empty_list_not_an_error(): void
    {
        Http::fake([self::URL => Http::response('<rss><channel><item>unclosed')]);

        $this->getJson('/api/news')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * The legacy read the feed on every request. A front page that calls a
     * third party once per visitor is a page that goes down when they do.
     */
    #[Test]
    public function the_feed_is_fetched_once_and_then_cached(): void
    {
        Http::fake([self::URL => Http::response($this->rss(
            '<item><title>Once</title><description>d</description></item>',
        ))]);

        $this->getJson('/api/news')->assertOk();
        $this->getJson('/api/news')->assertOk();
        $this->getJson('/api/news')->assertOk();

        Http::assertSentCount(1);
    }
}
