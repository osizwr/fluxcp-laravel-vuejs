<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Support\Client\ClientRoutes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The paths in server-generated links have to exist in the client router.
 *
 * Laravel cannot check this for us. The panel is a single-page application, so
 * every one of these resolves to the catch-all SPA route -- `route()` would
 * confirm a misspelt path just as readily as a correct one, and the browser
 * would get the shell and then a "page not found" from the Vue router.
 *
 * The cost of that going unnoticed is specific: an outstanding confirmation or
 * reset link stops working, and the people it affects are the ones who cannot
 * sign in, so they cannot report it either.
 */
final class ClientLinkTest extends TestCase
{
    private function routerSource(): string
    {
        $path = base_path('resources/js/router/index.ts');

        $this->assertFileExists($path, 'The client router is where these paths are declared.');

        return (string) file_get_contents($path);
    }

    #[Test]
    public function every_linked_path_is_declared_in_the_client_router(): void
    {
        $source = $this->routerSource();

        foreach (ClientRoutes::all() as $path) {
            $this->assertStringContainsString(
                "path: '{$path}'",
                $source,
                "The client router has no route for {$path}, so links to it would 404. "
                .'Either add the route or update App\Support\Client\ClientRoutes.',
            );
        }
    }

    #[Test]
    public function the_paths_used_in_mail_are_the_ones_declared(): void
    {
        /*
         * Guards against a link being built by hand at a call site instead of
         * going through ClientRoutes, which would put it outside the check
         * above.
         */
        $mailer = (string) file_get_contents(base_path('app/Services/Mail/AccountMailer.php'));

        foreach (
            [
                ClientRoutes::CONFIRM_ACCOUNT,
                ClientRoutes::RESET_PASSWORD,
                ClientRoutes::CONFIRM_EMAIL_CHANGE,
            ] as $path
        ) {
            $this->assertStringNotContainsString(
                "'{$path}'",
                $mailer,
                "AccountMailer should reference {$path} through a ClientRoutes constant "
                .'rather than as a literal.',
            );
        }

        $this->assertStringContainsString('ClientRoutes::url(', $mailer);
    }

    #[Test]
    public function a_built_url_is_absolute_and_carries_its_query(): void
    {
        config()->set('app.url', 'https://panel.example.test');

        $url = ClientRoutes::url(ClientRoutes::RESET_PASSWORD, [
            'token' => 'abc123',
            'server' => 'main',
        ]);

        // Absolute, because a relative path in an e-mail has nothing to
        // resolve against.
        $this->assertSame(
            'https://panel.example.test/reset-password?token=abc123&server=main',
            $url,
        );
    }

    #[Test]
    public function a_trailing_slash_on_the_app_url_does_not_double_up(): void
    {
        config()->set('app.url', 'https://panel.example.test/');

        $this->assertSame(
            'https://panel.example.test/forgot-password',
            ClientRoutes::url(ClientRoutes::FORGOT_PASSWORD),
        );
    }
}
