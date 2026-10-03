@extends('mail.layout', ['preview' => 'The password for your account was changed.'])

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:600;letter-spacing:-0.01em;">Your password was changed</h1>

    <p style="margin:0 0 14px;">
        The password for <strong>{{ $username }}</strong> was changed. You can sign in
        with the new one now, in the game and on the website.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;font-size:14px;color:#4a4f60;">
        <tr>
            <td style="padding:2px 14px 2px 0;color:#6b7080;">When</td>
            <td style="padding:2px 0;">{{ $changedAt }}</td>
        </tr>
        <tr>
            <td style="padding:2px 14px 2px 0;color:#6b7080;">From</td>
            <td style="padding:2px 0;">{{ $changedFromIp }}</td>
        </tr>
    </table>
@endsection

@section('footer')
    <p style="margin:8px 0 0;">
        If this was not you, somebody else has access to this account. Reset the
        password and contact an administrator.
    </p>
@endsection
