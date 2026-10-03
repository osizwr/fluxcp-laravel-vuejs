Confirm your new e-mail address

The account {{ $username }} asked to use {{ $newEmail }} as its e-mail address.
Open the link below to confirm it.

{{ $actionUrl }}

The link is valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }} and can be used once.
The account keeps its current address until you open it.

If you did not ask for this, you can ignore this message. The address on the
account will not be changed.
@if ($gameName !== '')

-- {{ $gameName }}
@endif
