{{--
    The shell every account e-mail is rendered into.

    Styles are inline and the layout is a single centred column, because mail
    clients strip <style> blocks and ignore most of what a stylesheet would
    say. The palette is deliberately neutral rather than taken from the active
    theme: a theme is a website skin, and an e-mail is read somewhere the theme
    does not apply.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f6;color:#1f2330;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preview ?? $subjectLine }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                    @if ($gameName !== '')
                        <tr>
                            <td style="padding:0 0 16px;font-size:18px;font-weight:600;letter-spacing:-0.01em;">
                                <a href="{{ $gameUrl }}" style="color:#1f2330;text-decoration:none;">{{ $gameName }}</a>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="background-color:#ffffff;border:1px solid #e3e4ea;border-radius:10px;padding:28px 26px;">
                            @yield('content')
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 2px 0;color:#6b7080;font-size:13px;line-height:1.5;">
                            {{--
                                No unsubscribe link. These are transactional:
                                each one is the direct result of something
                                somebody did with this account, and there is
                                nothing to unsubscribe from.
                            --}}
                            <p style="margin:0;">
                                This message was sent because of activity on an account
                                @if ($gameName !== '') at {{ $gameName }} @endif
                                using this address.
                            </p>
                            @yield('footer')
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
