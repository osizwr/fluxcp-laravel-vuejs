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
            'announcement' => $this->announcement(),
            'features' => $this->features(),
            'accounts' => $this->accounts(),
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
     * What the account forms need to know before they render.
     *
     * Three kinds of thing, and it is worth being clear why each is safe to
     * publish:
     *
     *   - Which flows are open. The client uses this to decide whether to show
     *     a "create an account" link at all, rather than rendering one that
     *     leads to a 403. An operator's answer to "is registration open" is
     *     already visible from trying it.
     *
     *   - The password policy. Deliberately published, so the form can state
     *     the rules before somebody submits rather than rejecting them
     *     afterwards. A password policy is not a secret: it is discoverable by
     *     anyone willing to submit the form twice, and keeping it hidden only
     *     costs legitimate users attempts.
     *
     *   - The CAPTCHA arrangement, including reCAPTCHA's *site* key, which is
     *     the public half of the pair and is meant to appear in page source.
     *     The secret key is read only by the server.
     *
     * @return array<string, mixed>
     */
    private function accounts(): array
    {
        $password = (array) $this->config->get('panel.registration.password', []);
        $captcha = $this->captchaGate();

        return [
            'registrationEnabled' => $this->config->get('panel.registration.enabled') === true,
            'passwordResetEnabled' => $this->config->get('panel.password_reset.enabled') === true,
            'emailChangeRequiresConfirmation' => $this->config->get('panel.email_change.require_confirmation') === true,
            'registrationRequiresConfirmation' => $this->config->get('panel.registration.require_email_confirmation') === true,

            'minimumAge' => (int) $this->config->get('panel.registration.minimum_age', 0),

            'username' => [
                'minLength' => (int) $this->config->get('panel.registration.username.min_length', 4),
                // rAthena's login.userid is varchar(23), not a preference.
                'maxLength' => 23,
            ],

            'password' => [
                'minLength' => (int) ($password['min_length'] ?? 8),
                'maxLength' => (int) ($password['max_length'] ?? 31),
                'minUppercase' => (int) ($password['min_uppercase'] ?? 0),
                'minLowercase' => (int) ($password['min_lowercase'] ?? 0),
                'minNumbers' => (int) ($password['min_numbers'] ?? 0),
                'minSymbols' => (int) ($password['min_symbols'] ?? 0),
                'allowUsernameInside' => ($password['allow_username_inside'] ?? false) === true,
            ],

            'captcha' => [
                'onRegistration' => $captcha['on_registration'],
                'onLogin' => $captcha['on_login'],
                'selfHosted' => $captcha['self_hosted'],
                // Public by design. The secret key never leaves the server.
                'siteKey' => $captcha['site_key'],
            ],
        ];
    }

    /**
     * The CAPTCHA facts, read from config rather than by resolving the driver.
     *
     * Resolving it would construct a driver on every page render -- and, with
     * an unrecognised driver name, throw while rendering the shell rather than
     * when a form is submitted.
     *
     * @return array{on_registration: bool, on_login: bool, self_hosted: bool, site_key: string|null}
     */
    private function captchaGate(): array
    {
        $selfHosted = $this->config->get('panel.captcha.driver', 'native') !== 'recaptcha';
        $siteKey = (string) $this->config->get('panel.captcha.recaptcha.site_key', '');

        return [
            'on_registration' => $this->config->get('panel.captcha.on_registration') === true,
            'on_login' => $this->config->get('panel.captcha.on_login') === true,
            'self_hosted' => $selfHosted,
            'site_key' => $selfHosted || $siteKey === '' ? null : $siteKey,
        ];
    }

    /**
     * The operator's announcement, or null when there is nothing to announce.
     *
     * Null rather than an empty object, so a block can be absent instead of
     * rendering an empty bar. A placeholder is worse than a missing section.
     *
     * The dismissal id is a hash of the message rather than something the
     * operator maintains: editing the text produces a new id, so an
     * announcement someone dismissed does not hide its replacement.
     *
     * @return array<string, mixed>|null
     */
    private function announcement(): ?array
    {
        $announcement = (array) $this->config->get('game.announcement', []);
        $message = trim((string) ($announcement['message'] ?? ''));

        if (($announcement['enabled'] ?? false) !== true || $message === '') {
            return null;
        }

        $tone = in_array($announcement['tone'] ?? null, ['info', 'event', 'maintenance'], true)
            ? (string) $announcement['tone']
            : 'info';

        $url = is_string($announcement['url'] ?? null) && trim((string) $announcement['url']) !== ''
            ? (string) $announcement['url']
            : null;

        return [
            'id' => substr(hash('sha256', $message), 0, 12),
            'message' => $message,
            'url' => $url,
            'label' => is_string($announcement['label'] ?? null) && $announcement['label'] !== ''
                ? (string) $announcement['label']
                : null,
            'tone' => $tone,
            'dismissible' => ($announcement['dismissible'] ?? true) === true,
        ];
    }

    /**
     * The operator's feature list.
     *
     * Entries without a title are dropped rather than rendered blank, and a
     * null url means the entry is plain text rather than a dead link.
     *
     * @return list<array{title: string, description: string, url: string|null}>
     */
    private function features(): array
    {
        $features = [];

        foreach ((array) $this->config->get('game.features', []) as $feature) {
            if (! is_array($feature)) {
                continue;
            }

            $title = trim((string) ($feature['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $url = is_string($feature['url'] ?? null) && trim((string) $feature['url']) !== ''
                ? (string) $feature['url']
                : null;

            $features[] = [
                'title' => $title,
                'description' => trim((string) ($feature['description'] ?? '')),
                'url' => $url,
            ];
        }

        return $features;
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
