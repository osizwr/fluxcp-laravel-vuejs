@extends('mail.layout', ['preview' => 'Confirm this address for your account.'])

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:600;letter-spacing:-0.01em;">Confirm your new e-mail address</h1>

    <p style="margin:0 0 14px;">
        The account <strong>{{ $username }}</strong> asked to use
        <strong>{{ $newEmail }}</strong> as its e-mail address. Follow the link below
        to confirm it.
    </p>

    @include('mail.button', ['url' => $actionUrl, 'label' => 'Confirm this address'])

    <p style="margin:0 0 14px;color:#6b7080;font-size:14px;">
        The link is valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }}
        and can be used once. The account keeps its current address until you follow it.
    </p>
@endsection

@section('footer')
    <p style="margin:8px 0 0;">
        If you did not ask for this, you can ignore this message. The address on the
        account will not be changed.
    </p>
@endsection
