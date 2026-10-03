<?php

declare(strict_types=1);

namespace App\Services\Theme;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The configuration handed to the browser.
 *
 * One payload, built in one place, so there is a single answer to "what does
 * the client know?". It is embedded in the page rather than compiled into the
 * bundle, which is what lets one build serve several environments and lets
 * APP_THEME and GAME_NAME take effect on the next request rather than the next
 * deploy.
 *
 * ---------------------------------------------------------------------------
 * Everything here is public
 * ---------------------------------------------------------------------------
 *
 * This ends up in the HTML source of every page, readable by anyone. Only
 * values that are safe to publish may be added:
 *
 *   - Reverb's app *key* is included. It is a public client identifier by
 *     design, the same way a Pusher key is; channel authorisation is a
 *     separate concern handled in routes/channels.php.
 *   - Reverb's app *secret* must never appear. It signs the server's calls to
 *     the websocket server. A test asserts its absence.
 *
 * If a value would be a problem in a "view source", it does not belong here.
 */
final readonly class ClientBootstrap
{
    public function __construct(
        private ConfigRepository $config,
        private ThemeService $themes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'game' => $this->game(),
            'theme' => $this->themes->active()->toClientArray(),
            'broadcasting' => $this->broadcasting(),
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Branding, from config/game.php.
     *
     * Links are filtered to those actually configured, so the client renders a
     * navigation item only when it leads somewhere. An unset link is an absent
     * item rather than a dead one.
     *
     * @return array<string, mixed>
     */
    private function game(): array
    {
        $links = [];

        foreach ((array) $this->config->get('game.links', []) as $key => $url) {
            if (is_string($url) && trim($url) !== '') {
                $links[(string) $key] = $url;
            }
        }

        return [
            'name' => (string) $this->config->get('game.name', ''),
            'shortName' => (string) $this->config->get('game.short_name', ''),
            'description' => (string) $this->config->get('game.description', ''),
            'version' => $this->config->get('game.version'),
            'logo' => $this->config->get('game.logo'),
            /*
             * Cast so an empty map encodes as {} rather than [], which keeps
             * the client's type honest instead of occasionally handing it an
             * array where it expects an object.
             */
            'links' => (object) $links,
        ];
    }

    /**
     * Websocket settings, or null when broadcasting is not configured.
     *
     * Null is meaningful to the client: it falls back to polling rather than
     * attempting a connection that cannot succeed.
     *
     * @return array<string, mixed>|null
     */
    private function broadcasting(): ?array
    {
        if ($this->config->get('broadcasting.default') !== 'reverb') {
            return null;
        }

        $key = $this->config->get('broadcasting.connections.reverb.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $options = (array) $this->config->get('broadcasting.connections.reverb.options', []);
        $scheme = ($options['scheme'] ?? 'http') === 'https' ? 'https' : 'http';

        return [
            'driver' => 'reverb',
            // The public client identifier. Never the secret.
            'key' => $key,
            'host' => (string) ($options['host'] ?? ''),
            'port' => (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 8080)),
            'scheme' => $scheme,
        ];
    }
}
