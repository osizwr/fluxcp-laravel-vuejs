@extends('mail.layout', ['preview' => 'Choose a new password for your account.'])

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:600;letter-spacing:-0.01em;">Choose a new password</h1>

    <p style="margin:0 0 14px;">
        Somebody asked to reset the password for <strong>{{ $username }}</strong>.
        Follow the link below to choose a new one.
    </p>

    @include('mail.button', ['url' => $actionUrl, 'label' => 'Choose a new password'])

    <p style="margin:0 0 14px;color:#6b7080;font-size:14px;">
        The link is valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }}
        and can be used once. Your current password keeps working until you set a new one.
    </p>
@endsection

@section('footer')
    <p style="margin:8px 0 0;">
        If you did not ask for this, you can ignore this message. Your password has
        not been changed, and nobody can change it without this link.
    </p>
@endsection
