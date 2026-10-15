@php
    /**
     * The shell behind every HTTP error page.
     *
     * The screen is the artwork and nothing else: no card, no header, no
     * footer, no navigation. A visitor who has hit a dead end is not there to
     * browse, and the usual furniture would be answering a question they did
     * not ask. What they do need -- a way home, and the fact that they are
     * about to be taken there -- sits on a scrim along the bottom.
     *
     * Deliberately self-contained: no @vite, no bundle, no stylesheet from the
     * build. An error page is what a visitor sees once something has already
     * gone wrong, and one that depends on the asset pipeline is one that
     * disappears exactly when the asset pipeline is what broke. The cost is a
     * copied handful of design tokens, which is cheap beside a blank page.
     *
     * Note which of these a browser actually reaches. routes/web.php answers
     * every path but `api`, `up`, `storage` and `build` with the SPA shell, so
     * a visitor who mistypes a URL gets the client's own not-found page rather
     * than 404 below. These render for the paths the SPA does not cover and
     * for aborts raised before the client is in play -- a throttled request, a
     * refused direct hit on /storage.
     *
     * `$status` is set by the view that includes this.
     */
    $art = file_exists(public_path("images/errors/{$status}.webp"))
        ? "/images/errors/{$status}.webp"
        : null;

    $title = __("errors.statuses.{$status}.title");
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    {{-- An error is never the page somebody should arrive at from a search. --}}
    <meta name="robots" content="noindex">

    <title>{{ $title }} — {{ config('game.name') }}</title>

    <link rel="icon" href="{{ config('game.favicon') ?: '/favicon.ico' }}" sizes="any">

    <style>
        /* The few tokens this page uses, copied from resources/css/app.css
           rather than imported, for the reason in the note at the top. There
           is no light and dark here: the page is a photograph edge to edge,
           and the only chrome over it is white on a dark scrim either way. */
        :root {
            --accent: oklch(0.62 0.14 68);
            --accent-strong: oklch(0.52 0.12 66);
            --radius-panel: 0.375rem;
        }

        *, *::before, *::after { box-sizing: border-box; }

        /* The buttons below are inline-flex, which would otherwise win over
           the browser's own rule for the attribute and show them anyway. */
        [hidden] { display: none !important; }

        body {
            margin: 0;
            /* Nothing scrolls: the picture is the page, and it is exactly the
               size of the window. */
            overflow: hidden;
            background: #000000;
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            line-height: 1.5;
        }

        /* Fixed to the viewport, and `dvh` rather than `vh` so a mobile
           browser's collapsing address bar does not leave a band of page
           showing underneath. */
        .screen {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100dvh;
            overflow: hidden;
        }

        /*
         * Edge to edge on every shape of screen, which means cropping rather
         * than fitting: the drawing is 16:9 and almost nothing is, so one
         * axis always has to overflow. Centred, so a tall screen takes the
         * middle of the picture and a wide one the middle band of it.
         */
        .screen .art {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }

        .controls {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.625rem;
            padding: 5rem 1rem calc(1.5rem + env(safe-area-inset-bottom));
            /* The artwork is bright in places and dark in others, so anything
               written over it needs its own ground rather than a colour that
               happens to work against one of them. */
            background-image: linear-gradient(
                to top,
                rgb(0 0 0 / 0.85) 0%,
                rgb(0 0 0 / 0.72) 45%,
                rgb(0 0 0 / 0) 100%
            );
            color: #ffffff;
            text-align: center;
        }

        .countdown {
            margin: 0;
            font-size: 0.8125rem;
            text-shadow: 0 1px 3px rgb(0 0 0 / 0.6);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.3125rem 0.75rem;
            border: 1px solid transparent;
            border-radius: var(--radius-panel);
            font: inherit;
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 150ms;
        }

        .btn.primary { background: var(--accent); color: #ffffff; }
        .btn.primary:hover { background: var(--accent-strong); }

        .btn.ghost { border-color: rgb(255 255 255 / 0.35); color: #ffffff; }
        .btn.ghost:hover { background: rgb(255 255 255 / 0.15); }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <main class="screen">
        {{--
            Decorative: the drawing spells its own status out in the scene,
            and the words of it are below for anybody it does not reach.
        --}}
        @if ($art)
            <img class="art" src="{{ $art }}" alt="" aria-hidden="true" decoding="async">
        @endif

        {{--
            The number goes with them: it is what somebody reporting the
            problem needs to quote, and with the artwork hidden from screen
            readers this is the one place it would otherwise be missing.
        --}}
        <div class="sr-only">
            <p>{{ $status }}</p>
            <h1>{{ $title }}</h1>
            <p>{{ __("errors.statuses.{$status}.body") }}</p>
        </div>

        {{--
            Everything that takes a click, on a scrim along the bottom. Both
            of these start hidden and are revealed by the script below, so a
            visitor without JavaScript is not told about a countdown that
            cannot run or offered a button to stop it.

            The countdown is not a live region: the number changes every
            second, and a live region would read the sentence out ten times
            over.
        --}}
        <div class="controls">
            <p class="countdown" id="error-countdown" hidden></p>

            {{-- Mounted empty and filled on cancel, rather than inserted at
                 that moment: a live region has to be in the document before
                 its contents change for the change to be announced. --}}
            <p class="sr-only" role="status" id="error-announce"></p>

            <div class="actions">
                <a class="btn primary" href="/">{{ __('errors.back') }}</a>

                <button class="btn ghost" type="button" id="error-stay" hidden>
                    {{ __('errors.stay') }}
                </button>
            </div>
        </div>
    </main>

    <script>
        /*
         * Sends the visitor home on its own after a short wait.
         *
         * A dead end is usually reached from a link somebody else wrote -- a
         * forum post, a stale bookmark -- and the visitor has no idea what the
         * right address was. Leaving them to find the way back is leaving them
         * to guess.
         *
         * The wait is cancellable, and that is not decoration: a page that
         * moves under somebody still reading it fails WCAG 2.2.1 unless they
         * can turn the timer off. It also stops the redirect from racing
         * somebody halfway through copying the broken URL out of the address
         * bar to report it.
         */
        (function () {
            var SECONDS = 10;

            var countdown = document.getElementById('error-countdown');
            var announce = document.getElementById('error-announce');
            var stay = document.getElementById('error-stay');

            var forms = @json(__('errors.countdown'));
            var stayed = @json(__('errors.stayed'));

            var remaining = SECONDS;
            var timer;

            function render() {
                var template = remaining === 1 ? forms.one : forms.other;

                countdown.textContent = template.replace(':count', String(remaining));
            }

            render();
            countdown.hidden = false;
            stay.hidden = false;

            stay.addEventListener('click', function () {
                clearInterval(timer);

                countdown.hidden = true;
                stay.hidden = true;
                announce.textContent = stayed;
            });

            timer = setInterval(function () {
                remaining -= 1;

                if (remaining > 0) {
                    render();

                    return;
                }

                clearInterval(timer);

                /*
                 * Replace rather than assign: assigning leaves the dead
                 * address in the history, so the first press of Back returns
                 * to this page, which counts down and throws the visitor
                 * forward again. That is a trap, not navigation.
                 */
                window.location.replace('/');
            }, 1000);
        })();
    </script>
</body>
</html>
