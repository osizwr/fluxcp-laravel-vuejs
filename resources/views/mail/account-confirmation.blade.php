@extends('mail.layout', ['preview' => 'Confirm your account to start playing.'])

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:600;letter-spacing:-0.01em;">Confirm your account</h1>

    <p style="margin:0 0 14px;">
        An account named <strong>{{ $username }}</strong> was registered with this address.
        Enter this code to activate it:
    </p>

    {{-- Letter-spaced and large, because it is read off the screen and typed. --}}
    <p style="margin:0 0 18px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:30px;font-weight:700;letter-spacing:0.22em;text-align:center;color:#1f2430;">
        {{ $code }}
    </p>

    <p style="margin:0 0 14px;color:#6b7080;font-size:14px;">
        Or confirm without typing it:
    </p>

    @include('mail.button', ['url' => $actionUrl, 'label' => 'Confirm my account'])

    <p style="margin:0 0 14px;color:#6b7080;font-size:14px;">
        The code and the link are valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }}
        and can be used once. Until one of them is used, the account cannot sign in.
    </p>
@endsection

@section('footer')
    <p style="margin:8px 0 0;">
        If you did not register this account, you can ignore this message — it will
        not be activated, and the name will be released for someone else to use.
    </p>
@endsection
