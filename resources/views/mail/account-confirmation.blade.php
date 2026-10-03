@extends('mail.layout', ['preview' => 'Confirm your account to start playing.'])

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:600;letter-spacing:-0.01em;">Confirm your account</h1>

    <p style="margin:0 0 14px;">
        An account named <strong>{{ $username }}</strong> was registered with this address.
        Follow the link below to activate it.
    </p>

    @include('mail.button', ['url' => $actionUrl, 'label' => 'Confirm my account'])

    <p style="margin:0 0 14px;color:#6b7080;font-size:14px;">
        The link is valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }}
        and can be used once. Until you follow it, the account cannot sign in.
    </p>
@endsection

@section('footer')
    <p style="margin:8px 0 0;">
        If you did not register this account, you can ignore this message — it will
        not be activated, and the name will be released for someone else to use.
    </p>
@endsection
