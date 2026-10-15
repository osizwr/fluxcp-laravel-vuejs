<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The HTML error pages in resources/views/errors.
 *
 * Worth pinning precisely because they are so rarely reached: the SPA answers
 * every ordinary path, so nobody notices a broken error page until the day
 * something else is already broken. Each test here is a thing that would
 * silently rot -- a renamed translation key, an illustration added for one
 * status and forgotten for the next, a stylesheet that quietly became a
 * dependency on the Vite build.
 */
final class ErrorPageTest extends TestCase
{
    /** @return list<array{int}> */
    public static function statuses(): array
    {
        return [[400], [403], [404], [408], [429]];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function it_renders_a_page_for_each_status(int $status): void
    {
        $html = view('errors.'.$status)->render();

        $this->assertStringContainsString((string) $status, $html);
        $this->assertStringContainsString(__("errors.statuses.{$status}.title"), $html);
        $this->assertStringContainsString(__("errors.statuses.{$status}.body"), $html);
    }

    #[Test]
    #[DataProvider('statuses')]
    public function it_shows_the_illustration_for_the_status(int $status): void
    {
        $this->assertFileExists(public_path("images/errors/{$status}.webp"));

        $this->assertStringContainsString(
            "/images/errors/{$status}.webp",
            view('errors.'.$status)->render(),
        );
    }

    /**
     * No @vite, no bundle, no stylesheet from the build.
     *
     * An error page that needs the asset pipeline disappears exactly when the
     * asset pipeline is what broke, which is one of the few times anybody
     * sees one of these at all.
     */
    #[Test]
    public function it_does_not_depend_on_the_build(): void
    {
        $html = view('errors.403')->render();

        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('@vite', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
    }

    /**
     * The countdown sentence is rendered by the page's own script once a
     * second, so it has to reach the browser as a template with both forms
     * rather than as one already-chosen string.
     */
    #[Test]
    public function it_hands_the_countdown_to_the_browser_as_a_template(): void
    {
        $html = view('errors.404')->render();

        $this->assertMatchesRegularExpression('/var forms = (\{.*?\});/', $html, 'no countdown forms');

        preg_match('/var forms = (\{.*?\});/', $html, $matches);

        /** @var array{one: string, other: string} $forms */
        $forms = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString(':count', $forms['one']);
        $this->assertStringContainsString(':count', $forms['other']);
    }

    /** Every language ships every string, or a visitor gets a raw key. */
    #[Test]
    public function every_language_translates_every_page(): void
    {
        foreach (['en', 'tl', 'pt_BR', 'th', 'ms', 'id'] as $locale) {
            $this->app->setLocale($locale);

            foreach ([400, 403, 404, 408, 429] as $status) {
                foreach (['title', 'body'] as $part) {
                    $key = "errors.statuses.{$status}.{$part}";

                    $this->assertNotSame($key, __($key), "{$locale} is missing {$key}");
                }
            }

            foreach (['errors.back', 'errors.stay', 'errors.stayed'] as $key) {
                $this->assertNotSame($key, __($key), "{$locale} is missing {$key}");
            }

            $this->assertIsArray(__('errors.countdown'), "{$locale} is missing errors.countdown");
        }
    }
}
