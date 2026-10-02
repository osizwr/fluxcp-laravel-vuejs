<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-site-name="{{ config('app.name') }}"
    @if (config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key'))
        data-reverb-key="{{ config('broadcasting.connections.reverb.key') }}"
        data-reverb-host="{{ config('broadcasting.connections.reverb.options.host') }}"
        data-reverb-port="{{ config('broadcasting.connections.reverb.options.port') }}"
        data-reverb-scheme="{{ config('broadcasting.connections.reverb.options.scheme') }}"
    @endif
>
{{--
    The SPA shell.

    Settings the client needs are passed as data attributes rather than compiled
    into the bundle, so one build can be deployed to several environments. Only
    the public Reverb app key is exposed here; the secret stays on the server.

    The theme is applied before the stylesheet loads so a visitor who prefers
    dark does not see a white flash on every navigation.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">

    <script>
        try {
            var stored = localStorage.getItem('panel.theme');
            if (stored === 'light' || stored === 'dark') {
                document.documentElement.dataset.theme = stored;
            }
        } catch (error) {
            /* Blocked site data is not a reason to fail to render. */
        }
    </script>

    @vite(['resources/js/app.ts'])
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
