@php
    /**
     * $theme and $bootstrapJson are supplied by ThemeServiceProvider's view
     * composer, so no controller needs to know the theme system exists.
     */
    $stylesheet = $theme->stylesheet();
    $entrypoints = array_values(array_filter([$stylesheet, 'resources/js/app.ts']));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-slug="{{ $theme->slug }}">
{{--
    The SPA shell.

    Two things are resolved here, per request, rather than at build time:

      - the active theme's stylesheet, so APP_THEME takes effect on the next
        request instead of the next deploy;
      - the client bootstrap payload, so one build serves every environment.

    The theme's CSS is a real <link> emitted by @vite rather than an import
    inside the bundle. That matters: a stylesheet loaded by JavaScript arrives
    after first paint, and a themed panel would visibly flash the unthemed
    palette on every cold load.

    See docs/THEMING.md.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    <title>{{ config('game.name') }}</title>
    <meta name="description" content="{{ config('game.description') }}">

    <link rel="icon" href="{{ config('game.favicon') ?: '/favicon.ico' }}" sizes="any">

    <script>
        /*
         * Applied before the stylesheet so a visitor who prefers dark does not
         * see a light flash. `appearance` is light/dark; it is deliberately a
         * different word from the theme slug above, which is the skin.
         */
        try {
            var stored = localStorage.getItem('panel.appearance');
            if (stored === 'light' || stored === 'dark') {
                document.documentElement.dataset.appearance = stored;
            }
        } catch (error) {
            /* Blocked site data is not a reason to fail to render. */
        }
    </script>

    {{-- Public configuration only. The Reverb secret is never included. --}}
    <script type="application/json" id="panel-bootstrap">{!! $bootstrapJson !!}</script>

    @vite($entrypoints)
</head>
<body>
    <div id="app"></div>

    <noscript>
        <p style="padding:1rem;font-family:system-ui">
            This control panel needs JavaScript enabled.
        </p>
    </noscript>
</body>
</html>
