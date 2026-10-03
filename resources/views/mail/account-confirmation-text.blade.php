Confirm your account

An account named {{ $username }} was registered with this address.
Open the link below to activate it.

{{ $actionUrl }}

The link is valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }} and can be used once.
Until you open it, the account cannot sign in.

If you did not register this account, you can ignore this message. It will not
be activated, and the name will be released for someone else to use.
@if ($gameName !== '')

-- {{ $gameName }}
@endif
