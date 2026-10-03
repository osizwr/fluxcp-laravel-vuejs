Your password was changed

The password for {{ $username }} was changed. You can sign in with the new one
now, in the game and on the website.

When: {{ $changedAt }}
From: {{ $changedFromIp }}

If this was not you, somebody else has access to this account. Reset the
password and contact an administrator.
@if ($gameName !== '')

-- {{ $gameName }}
@endif
