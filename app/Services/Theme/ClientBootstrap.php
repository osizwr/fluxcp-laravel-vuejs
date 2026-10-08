<?php

declare(strict_types=1);

namespace App\Services\Theme;

use App\Http\Middleware\SetLocale;
use App\Support\Tokens\OneTimeCode;
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
            'locale' => $this->locale(),
            'game' => $this->game(),
            'theme' => $this->themes->active()->toClientArray(),
            'broadcasting' => $this->broadcasting(),
            'announcement' => $this->announcement(),
            'features' => $this->features(),
            'downloads' => $this->downloads(),
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
            'nav' => $this->navLinks((array) $this->config->get('game.nav', [])),
            'footer' => $this->footer(),
            'legal' => $this->legal(),
        ];
    }

    /**
     * The footer's link groups and social buttons, from config/game.php.
     *
     * Normalised here so the client receives one shape rather than three. Each
     * link arrives as a label plus at most one destination:
     *
     *   to      an internal route
     *   href    an external address
     *   neither a section that does not exist yet
     *
     * Unlike the navigation links above, an entry with no destination is kept
     * rather than dropped. The footer is a map of what the server offers, and
     * an operator standing one up wants the shape of it visible while the
     * pages are still being built.
     *
     * @return array<string, mixed>
     */
    private function footer(): array
    {
        $groups = [];

        foreach ((array) $this->config->get('game.footer.groups', []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $heading = $this->trimmedOrNull($group['heading'] ?? null);

            $links = [];

            $links = $this->navLinks((array) ($group['links'] ?? []));

            // A heading with nothing under it is a column of whitespace.
            if ($heading === null || $links === []) {
                continue;
            }

            $groups[] = ['heading' => $heading, 'links' => $links];
        }

        $socials = [];

        foreach ((array) $this->config->get('game.footer.socials', []) as $social) {
            if (! is_array($social)) {
                continue;
            }

            $network = $this->trimmedOrNull($social['network'] ?? null);

            if ($network === null) {
                continue;
            }

            $socials[] = [
                'network' => $network,
                'href' => $this->trimmedOrNull($social['url'] ?? null),
            ];
        }

        return ['groups' => $groups, 'socials' => $socials];
    }

    /**
     * The footer's small print, from config/game.php.
     *
     * Here rather than written into a theme because a test forbids a frontend
     * file from containing the game's name, and the affiliation notice is the
     * one piece of copy that has to say it. Keeping it in configuration means
     * the notice survives a rebrand and a theme change alike.
     *
     * Every field is nullable, and null means the footer omits that line
     * rather than printing an empty one.
     *
     * @return array<string, mixed>
     */
    private function legal(): array
    {
        $name = (string) $this->config->get('game.name', '');

        $disclaimer = $this->config->get('game.legal.disclaimer');

        $creditName = $this->trimmedOrNull($this->config->get('game.legal.credit.name'));

        return [
            /*
             * `:name` is substituted here rather than in the browser so the
             * client renders a finished sentence. A placeholder is a server
             * concern; the footer should not have to know the convention.
             */
            'disclaimer' => is_string($disclaimer) && trim($disclaimer) !== ''
                ? str_replace(':name', $name, trim($disclaimer))
                : null,

            // Falls back to the game name, which is the common case.
            'copyright' => $this->trimmedOrNull($this->config->get('game.legal.copyright'))
                ?? ($name !== '' ? $name : null),

            /*
             * Dropped entirely without a name: a URL with nothing to label it
             * has nothing to render as.
             */
            'credit' => $creditName === null ? null : [
                'name' => $creditName,
                'url' => $this->trimmedOrNull($this->config->get('game.legal.credit.url')),
            ],
        ];
    }

    /**
     * Normalises a configured list of links, for the masthead or for one
     * footer group.
     *
     * Each entry arrives as a label plus at most one destination:
     *
     *   to      an internal route
     *   href    an external address
     *   neither a section that does not exist yet
     *
     * An entry with no destination is kept rather than dropped, which is the
     * one place this differs from `game.links` above. These lists are a map of
     * what the server offers, and an operator standing one up wants the shape
     * of it visible while the pages are still being built.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<array<string, string|null>>
     */
    private function navLinks(array $entries): array
    {
        $links = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = $this->trimmedOrNull($entry['label'] ?? null);

            if ($label === null) {
                continue;
            }

            $href = $this->trimmedOrNull($entry['url'] ?? null);

            $links[] = [
                'label' => $label,
                /*
                 * A configured URL wins over the built-in route. That is what
                 * lets an operator point Download at a CDN without having to
                 * remove the internal page it would otherwise open, and put it
                 * back by clearing one env var.
                 */
                'to' => $href === null ? $this->trimmedOrNull($entry['to'] ?? null) : null,
                'href' => $href,
            ];
        }

        return $links;
    }

    /**
     * A configured string, or null when it is absent or blank.
     *
     * Blank counts as absent throughout this payload: an operator who clears
     * an env var leaves `FOO=` behind as often as they delete the line, and
     * the two should mean the same thing.
     */
    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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

            /*
             * Not configurable, and published anyway: the confirmation form
             * draws one input per digit, so it needs the count to render at
             * all. Reading it from the class that generates the codes is what
             * stops a form of six boxes outliving a change to five.
             */
            'confirmationCodeLength' => OneTimeCode::LENGTH,

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
     * The active language, and the ones a visitor may switch to.
     *
     * Sent as BCP 47 -- `pt-BR`, not `pt_BR` -- because that is what the
     * client puts in the `lang` attribute and in its cookie. SetLocale does
     * the one conversion to Laravel's directory naming on the way back in.
     *
     * The list is sent rather than compiled into the bundle so that the
     * picker cannot offer a language the server would refuse to serve.
     *
     * @return array{active: string, available: list<string>}
     */
    private function locale(): array
    {
        return [
            'active' => str_replace('_', '-', app()->getLocale()),
            'available' => array_map(
                static fn (string $locale): string => str_replace('_', '-', $locale),
                SetLocale::SUPPORTED,
            ),
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
     * The download page's contents, from config/game.php.
     *
     * Normalised here so the client receives one shape rather than whatever an
     * operator's array happened to look like, and so a half-filled section is
     * dropped on the server instead of rendering as a gap in the page.
     *
     * What is dropped, and why:
     *
     *   a package with no name          nothing to label the card with
     *   a mirror with no label or URL   a button that downloads nothing
     *   a requirement row missing either half   half a table row
     *   a step with no title            a numbered disc beside nothing
     *
     * A package whose mirrors all dropped out is deliberately *kept*. That is
     * the state every install starts in, and an operator standing one up wants
     * to see the card they are about to fill rather than a blank page; the
     * client draws it as not yet available.
     *
     * @return array<string, mixed>
     */
    private function downloads(): array
    {
        return [
            'notice' => $this->trimmedOrNull($this->config->get('game.downloads.notice')),
            'clients' => $this->downloadClients(),
            'requirements' => $this->downloadRequirements(),
            'steps' => $this->downloadSteps(),
        ];
    }

    /**
     * The packages on offer.
     *
     * `platform` is checked against the list the client draws an icon for, so
     * a typo renders a card without one rather than a broken image or, worse,
     * an icon for the wrong operating system.
     *
     * @return list<array<string, mixed>>
     */
    private function downloadClients(): array
    {
        $platforms = ['windows', 'macos', 'linux', 'android', 'ios'];
        $clients = [];

        foreach ((array) $this->config->get('game.downloads.clients', []) as $client) {
            if (! is_array($client)) {
                continue;
            }

            $name = $this->trimmedOrNull($client['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $platform = $this->trimmedOrNull($client['platform'] ?? null);

            $mirrors = [];

            foreach ((array) ($client['mirrors'] ?? []) as $mirror) {
                if (! is_array($mirror)) {
                    continue;
                }

                $label = $this->trimmedOrNull($mirror['label'] ?? null);
                $url = $this->trimmedOrNull($mirror['url'] ?? null);

                // Both halves or neither: a labelled button that leads nowhere
                // is worse than an absent one.
                if ($label === null || $url === null) {
                    continue;
                }

                $mirrors[] = ['label' => $label, 'url' => $url];
            }

            $notes = [];

            foreach ((array) ($client['notes'] ?? []) as $note) {
                $trimmed = $this->trimmedOrNull($note);

                if ($trimmed !== null) {
                    $notes[] = $trimmed;
                }
            }

            $clients[] = [
                'name' => $name,
                'platform' => in_array($platform, $platforms, strict: true) ? $platform : null,
                'badge' => $this->trimmedOrNull($client['badge'] ?? null),
                'version' => $this->trimmedOrNull($client['version'] ?? null),
                'size' => $this->trimmedOrNull($client['size'] ?? null),
                'updated' => $this->trimmedOrNull($client['updated'] ?? null),
                'description' => (string) ($this->trimmedOrNull($client['description'] ?? null) ?? ''),
                'mirrors' => $mirrors,
                'notes' => $notes,
            ];
        }

        return $clients;
    }

    /**
     * The specification tables, one per tab.
     *
     * A group with a heading and no rows is dropped rather than rendered as an
     * empty tab, which is a tab somebody clicks once and learns nothing from.
     *
     * @return list<array<string, mixed>>
     */
    private function downloadRequirements(): array
    {
        $groups = [];

        foreach ((array) $this->config->get('game.downloads.requirements', []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $heading = $this->trimmedOrNull($group['heading'] ?? null);

            $rows = [];

            foreach ((array) ($group['rows'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $label = $this->trimmedOrNull($row['label'] ?? null);
                $value = $this->trimmedOrNull($row['value'] ?? null);

                if ($label === null || $value === null) {
                    continue;
                }

                $rows[] = ['label' => $label, 'value' => $value];
            }

            if ($heading === null || $rows === []) {
                continue;
            }

            $groups[] = ['heading' => $heading, 'rows' => $rows];
        }

        return $groups;
    }

    /**
     * The installation steps, in the order configured.
     *
     * Numbered by the client from their position rather than carrying a number
     * here, so removing the third step renumbers the rest instead of leaving a
     * list that counts 1, 2, 4.
     *
     * @return list<array{title: string, description: string}>
     */
    private function downloadSteps(): array
    {
        $steps = [];

        foreach ((array) $this->config->get('game.downloads.steps', []) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $title = $this->trimmedOrNull($step['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $steps[] = [
                'title' => $title,
                'description' => (string) ($this->trimmedOrNull($step['description'] ?? null) ?? ''),
            ];
        }

        return $steps;
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
