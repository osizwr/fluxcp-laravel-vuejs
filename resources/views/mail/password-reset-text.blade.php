Choose a new password

Somebody asked to reset the password for {{ $username }}.
Open the link below to choose a new one.

{{ $actionUrl }}

The link is valid for {{ $expiresInHours }} {{ \Illuminate\Support\Str::plural('hour', $expiresInHours) }} and can be used once.
Your current password keeps working until you set a new one.

If you did not ask for this, you can ignore this message. Your password has not
been changed, and nobody can change it without this link.
@if ($gameName !== '')

-- {{ $gameName }}
@endif
