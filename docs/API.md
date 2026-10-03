# API Reference

The endpoints the Vue client uses. Eight exist today; the matrix lists what is
still to come.

## Conventions

**Base path** is `/api`. Everything returns JSON.

**Authentication is by session cookie.** The API is registered inside Laravel's
`web` middleware group, so a request must send cookies and carry the CSRF token
from the `XSRF-TOKEN` cookie in an `X-XSRF-TOKEN` header. No bearer token is
issued to the browser — see [MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md)
(D11). `resources/js/services/api.ts` does both for every request.

**Route names are the legacy identifiers.** Each endpoint below gives its
`<module>.<action>` name, which is also its key in `config/permissions.php` and
its row in [FLUXCP_FEATURE_INVENTORY.md](FLUXCP_FEATURE_INVENTORY.md).

**Every endpoint is authorised server-side.** A route whose name has no entry in
the permission map is refused with `403`, so an endpoint cannot become publicly
reachable by omission (D3).

**The selected server group** comes from a `server` query parameter when given,
and otherwise from the session. A `world` parameter selects the char/map pair
within it. An unknown value falls back to the default rather than erroring,
because it usually means a stale bookmark.

### Status codes

| Code | Means |
| --- | --- |
| `200` | Success. |
| `401` | Not signed in. |
| `403` | Signed in but not permitted, or a guests-only route reached while signed in. |
| `404` | No such resource. |
| `422` | Validation failed. Errors are keyed by field. |
| `429` | Rate limited. |
| `503` | Refused because War of Emperium is in progress. |

### Error shape

```json
{
  "message": "The given data was invalid.",
  "errors": { "username": ["These credentials do not match our records."] }
}
```

---

## Authentication

### `POST /api/auth/login`

Route name `account.login`. Access: **guests only**.

A signed-in visitor gets `403`, because the permission map holds this at
`UNAUTH`, which means guests only rather than guests and above.

```json
{
  "username": "merchant",
  "password": "…",
  "server": "main",
  "remember": false
}
```

| Field | Rules |
| --- | --- |
| `username` | required, max 23 — the width of `login.userid` |
| `password` | required. No complexity rules; those belong to registration, and applying them here would lock out older passwords and leak the policy |
| `server` | optional, defaults to the configured default group |
| `remember` | optional boolean |

**`200`** returns the account, shaped as below.

**`422`** for bad credentials, a banned account, an unconfirmed registration, or
a banned address. The message is deliberately vague for bad credentials and
specific for the others, which are only reachable once the password has already
been verified and so disclose nothing to someone guessing.

**`429`** once throttled: 5 attempts per account and address, 30 per address,
over 60 seconds by default. A success clears the counter.

Side effects: the session id is regenerated (preventing session fixation), the
attempt is recorded in `cp_loginlog` **without the password** (D2), and the
panel's own hash of the password is written or refreshed (D1).

### `POST /api/auth/logout`

Route name `account.logout`. Access: **signed in**.

Invalidates the session and regenerates the CSRF token.

```json
{ "message": "Signed out." }
```

### `GET /api/account`

Route name `account.view`. Access: **signed in**.

Pass `?with_permissions=1` to include the viewer's ability list under `meta`.

```json
{
  "data": {
    "id": 2000001,
    "username": "merchant",
    "email": "player@example.com",
    "gender": "M",
    "group": {
      "id": 0,
      "name": "Player",
      "level": 0,
      "label": "Players",
      "is_staff": false
    },
    "state": {
      "code": 0,
      "label": "Normal",
      "permanently_banned": false,
      "temporarily_banned": false,
      "ban_expires_at": null,
      "expired": false
    },
    "is_vip": false,
    "character_slots": 9,
    "login_count": 42,
    "last_login_at": "2026-10-02T09:15:00+00:00",
    "birthdate": "1995-04-12",
    "credits": 0
  },
  "meta": {
    "permissions": ["SearchWhosOnline", "SeeItemDbScripts", "HideFromZenyRank", "Donate"]
  }
}
```

`user_pass`, `pincode` and `web_auth_token` are never returned. The resource
lists fields explicitly rather than spreading the model, so a column rAthena adds
in future cannot leak through by default.

The permission list is **a convenience for the interface only**. Every ability is
enforced again on the server; hiding a control is never what stops an action.

> **Currently the signed-in account only.** Viewing another account (FluxCP's
> `ViewAccount` ability) is not built.

---

## Registration and confirmation

### `POST /api/auth/register`

Route name `account.create`. Access: **guests only**.

```json
{
  "username": "merchant",
  "password": "…",
  "password_confirmation": "…",
  "email": "player@example.com",
  "email_confirmation": "player@example.com",
  "gender": "M",
  "birthdate": "1995-04-12",
  "server": "main",
  "captcha": "ABCDE"
}
```

| Field | Rules |
| --- | --- |
| `username` | required. Length and character rules come from `panel.registration.username`; the maximum is 23, the width of `login.userid` |
| `password` | required, must match `password_confirmation`. Policy from `panel.registration.password`; the maximum is capped at 32 by `login.user_pass` regardless of the setting |
| `email` | required, valid, max 39 — the width of `login.email` — and must match `email_confirmation` |
| `gender` | required, `M` or `F`. `S` is rAthena's server-account marker and is refused |
| `birthdate` | required, `YYYY-MM-DD`, in the past, and at least `panel.registration.minimum_age` years ago |
| `server` | optional, defaults to the configured default group |
| `captcha` | required when `panel.captcha.on_registration` is on |

**`201`**, when no confirmation is required, returns the account and signs it
in — the legacy flow did the same. The response carries `message`.

**`201`**, when confirmation is required, returns no account:

```json
{
  "message": "Your account has been created. Check player@example.com for the link that activates it.",
  "requires_confirmation": true,
  "confirmation_sent": true
}
```

`confirmation_sent` is reported honestly. A `false` means the account exists
but the e-mail did not go out, and the person should ask for another rather
than wait for one that is not coming.

**`403`** when `panel.registration.enabled` is off, with a message rather than
an authorisation error — registration being closed is an operator's decision
the visitor should be told about.

**`422`** for any validation failure, field-keyed.

**`429`** after 5 registrations from one address in 10 minutes.

Side effects: the account and its `cp_createlog` row are written in one
transaction, the latter **without the password** (D2); the panel's own hash is
stored (D1); and when confirmation is required the account is held in rAthena
state 5 with a `cp_banlog` row that does **not** contain the token.

### `POST /api/auth/confirm`

Route name `account.confirm`. Access: **guests only** — an account awaiting
confirmation cannot sign in, so whoever follows the link is a guest.

```json
{ "token": "…64 hex characters…", "server": "main" }
```

**`200`** on success. The account leaves state 5, the registration row is
marked confirmed, its token is cleared, and the lift is recorded in
`cp_banlog`.

**`422`** for an unknown, expired, already-used token, with one message for all
three. Distinguishing them would tell the holder of a stale link which case
they have, and none is actionable differently.

**`429`** after 10 attempts from one address in 15 minutes.

### `POST /api/auth/confirm/resend`

Route name `account.resend`. Access: **guests only**.

```json
{ "username": "merchant", "email": "player@example.com", "server": "main" }
```

Both fields are required and must match the account, so this cannot be used to
mail a confirmation link to an address somebody merely typed in.

**`200`** always, with the same message whether or not anything matched.

A new token is issued rather than the old one re-sent, which retires the
previous link and lets a lapsed request be renewed.

**`429`** after 5 attempts per address or 3 per account in 15 minutes.

---

## Password reset

### `POST /api/auth/password/forgot`

Route name `account.resetpass`. Access: **guests only**.

```json
{ "username": "merchant", "email": "player@example.com", "server": "main" }
```

**`200`** always, with the same message in every case — including when the
account does not exist, when the address does not match, and when the account
is staff that `panel.password_reset.blocked_at_or_above_level` protects. The
form therefore cannot be used to discover which addresses are registered or
which accounts belong to game masters.

Accounts not in state 0, and rAthena's server accounts, are silently excluded.

**`403`** when `panel.password_reset.enabled` is off.

**`429`** after 5 attempts per address or 3 per account in 15 minutes.

Side effects: any earlier outstanding request is retired, so only the newest
link works; the new row stores a **digest** of the token and empty password
columns (D2, D15).

### `POST /api/auth/password/reset`

Route name `account.resetpw`. Access: **guests only**.

```json
{
  "token": "…64 hex characters…",
  "password": "…",
  "password_confirmation": "…",
  "server": "main"
}
```

The token is the only thing the link carries. No account name or id, because
nothing else in it is a secret.

**`200`** on success. The password is written to rAthena's column in the
emulator's format and to the panel's hash (D1), **every** session for the
account is ended (D18), and a notice goes to the address on the account.

**`422`** for an invalid or expired token, or a password that fails the policy.
A rejected password leaves the link usable, so a typo does not cost a new link.

**`429`** after 10 attempts from one address in 15 minutes.

No password is ever e-mailed by this endpoint or any other (D16).

---

## Account credentials

### `PUT /api/account/password`

Route name `account.changepass`. Access: **signed in**.

```json
{
  "current_password": "…",
  "password": "…",
  "password_confirmation": "…"
}
```

**`200`**:

```json
{ "message": "Your password has been changed.", "other_sessions_revoked": true }
```

The current session survives with a regenerated id; every other session for
the account is ended. `other_sessions_revoked` is `false` when the session
driver cannot be queried — with `file` or `cookie` there is nothing to delete,
and claiming otherwise would be a false reassurance (D18).

Staff accounts are held to the stricter policy in
`panel.registration.password.staff` (D17).

**`422`** for a wrong current password, a mismatch, a reused password, or a
policy failure.

**`429`** after 10 attempts in 15 minutes.

### `PUT /api/account/email`

Route name `account.changemail`. Access: **signed in**.

```json
{
  "current_password": "…",
  "email": "new@example.com",
  "email_confirmation": "new@example.com"
}
```

The current password is required. The legacy form asked for nothing, which made
a stolen session cookie enough to move the address — and the address is what
password reset trusts.

**`200`** with `panel.email_change.require_confirmation` on:

```json
{
  "message": "Check new@example.com for the link that confirms the change. …",
  "email": "old@example.com",
  "requires_confirmation": true,
  "confirmation_sent": true
}
```

`email` is still the **current** address, because it does not move until the
link is followed. With confirmation off, the change is applied immediately and
`email` is the new address.

**`422`** for a wrong current password, the address already being the
account's, an address another account holds (unless
`panel.registration.allow_duplicate_emails`), or a mismatch.

**`429`** after 5 attempts in 15 minutes.

### `POST /api/account/email/confirm`

Route name `account.confirmemail`. Access: **signed in**.

```json
{ "token": "…64 hex characters…" }
```

Requires a session, and the request must belong to the signed-in account — so a
token read out of somebody's mailbox is not on its own enough to move their
address. This is the legacy behaviour, kept.

**`200`** returns the address now on the account.

**`422`** for an invalid or expired token, or when the address has been taken
since the request was made.

---

## CAPTCHA

### `GET /api/captcha`

Route name `captcha.index`. Access: **everyone**.

Returns `image/png` with `Cache-Control: no-store, …, private`. A cached
challenge is one image answered many times.

Each request replaces any outstanding challenge, so only the most recently
issued image is accepted. A challenge expires after
`panel.captcha.native.expires_after_seconds` and is consumed when checked —
including on a wrong answer, so one image cannot be brute-forced.

**`404`** when `PANEL_CAPTCHA_DRIVER=recaptcha`: there is no image to serve.

The answer is compared case-insensitively, and the session stores a SHA-256 of
it rather than the answer itself.

---

## Characters

### `GET /api/characters/mine`

Route name `character.mine`. Access: **signed in**.

The signed-in account's characters, ordered by slot. Characters queued for
deletion are excluded — rAthena does not remove a character immediately, it sets
`delete_date` to when the deletion becomes final.

```json
{
  "data": [
    {
      "id": 150001,
      "name": "Mercator",
      "slot": 0,
      "job_id": 5,
      "job_name": "Merchant",
      "base_level": 42,
      "job_level": 18,
      "zeny": 125000,
      "online": false,
      "guild": { "id": 3, "name": "Traders" },
      "is_married": false,
      "pending_deletion": false,
      "deletion_final_at": null,
      "last_login_at": "2026-10-01T22:10:00+00:00"
    }
  ]
}
```

This route has no legacy equivalent — FluxCP folded it into `account/view`'s
template. It is declared in the permission map's `added_routes` block so the
ported block stays a faithful record of `access.php`.

### `GET /api/characters/online`

Route name `character.online`. Access: **anyone**. Paginated.

| Parameter | |
| --- | --- |
| `page` | page number |
| `per_page` | clamped to `panel.pagination.max_per_page` |
| `name` | partial name search, wildcards escaped |

Two fields are withheld by permission, which is why the same request returns
different shapes for different viewers:

- **`map`** needs `ViewOnlinePosition`. A public page showing locations lets
  players track each other, and during a siege lets guilds scout castles.
- **`account_id`** needs `SeeAccountID`.

Characters whose owner set the `Hidden` preference are excluded unless the viewer
holds `IgnoreHiddenPref`.

**`503`** while War of Emperium is running on the selected world, unless the
viewer holds `ViewWoeDisallowed`. Restricted routes are configured per char/map
pair in `config/rathena.php`.

Returns Laravel's standard paginator envelope: `data`, `links`, `meta`.

---

## Rankings

### `GET /api/rankings/level`

Route name `ranking.character`. Access: **anyone**.

### `GET /api/rankings/zeny`

Route name `ranking.zeny`. Access: **anyone**.

| Parameter | |
| --- | --- |
| `limit` | clamped to `panel.pagination.max_per_page` |
| `job_class` | filter to one rAthena job id |

```json
{
  "data": [
    {
      "rank": 1,
      "character": {
        "id": 150001,
        "name": "Mercator",
        "job_id": 5,
        "job_name": "Merchant",
        "base_level": 99,
        "job_level": 70,
        "zeny": 2000000000
      },
      "guild": { "id": 3, "name": "Traders", "emblem_id": 1947 }
    }
  ]
}
```

Exclusions applied by the server, each configurable under `panel.rankings`:

| Excluded | Why |
| --- | --- |
| Permanently banned accounts | A ladder populated by banned accounts is not one players trust |
| Staff accounts, at or above a configured level | Staff can grant themselves anything, so their characters are not comparable |
| Characters queued for deletion | Not in the world any more |
| Inactive accounts, optionally | FluxCP's `CharRankingThreshold` |
| Temporarily banned accounts, optionally | Off by default: a timeout is not a reason to erase progress |
| Characters with `HideFromZenyRanking` | Zeny ladder only. Players set it so being wealthy does not advertise them as a target |

The level ladder orders by base level, then base exp, job level, job exp, and
char id, which is FluxCP's ordering exactly.

> Excluding banned and staff accounts means joining `char` to `login`, which live
> in different databases and may live on different hosts. The service detects
> co-location and uses a cross-database join when it holds, and computes an
> exclusion set separately when it does not. FluxCP interpolated database names
> into its SQL, which silently requires the two to be co-located, so its rankings
> fail outright on a split setup.

---

## Server status

### `GET /api/server/status`

Route name `server.status`. Access: **anyone**.

```json
{
  "data": [
    {
      "key": "main",
      "name": "rAthena",
      "login_server_up": true,
      "players_online": 1247,
      "servers": [
        {
          "key": "main",
          "name": "rAthena",
          "login_server_up": true,
          "char_server_up": true,
          "map_server_up": true,
          "playable": true,
          "players_online": 1247,
          "players_peak": 1983,
          "woe_active": false
        }
      ]
    }
  ],
  "meta": {
    "players_online": 1247,
    "measured_at": "2026-10-02T09:19:46+00:00",
    "cache_seconds": 30
  }
}
```

Every figure is measured: reachability from an actual TCP connection to each
process, and the player count from rAthena's own `char.online` column, which the
map server maintains.

`playable` means all three processes answered — the login server to
authenticate, the char server to pick a character, the map server to enter the
world. All three are reported separately because which one is down tells a
player whether to wait or to report it.

`players_peak` is `null` unless `panel.server_status.show_peak` is on, since
`cp_onlinepeak` is only populated if something records into it.

`cache_seconds` is how long the measurement is trusted, so a client knows there
is no point polling faster.

If a group's database is unreachable the endpoint still answers, reporting zero
players alongside its processes showing as down. The status page is the page
people load when something looks broken, so it must not itself fail.

---

## Server statistics

### `GET /api/server/statistics`

Route name `server.statistics`. Access: **anyone**.

Aggregate counts over the game tables, for the statistics block.

```json
{
  "data": {
    "accounts": 1842,
    "characters": 5310,
    "guilds": 94,
    "players_online": 1247
  },
  "meta": { "cache_seconds": 300 }
}
```

Every figure is a real query. Accounts exclude rAthena's own inter-server
accounts (`sex = 'S'`) and accounts disabled with a negative `group_id`;
characters exclude those queued for deletion.

**There is no uptime field, deliberately.** rAthena records no start time the
panel can read, so the figure would have to be invented — and a statistics
endpoint is the last place an invented number belongs. A test asserts its
absence.

These are full-table counts behind a landing page, so they are cached;
`cache_seconds` says for how long.

---

## Character classes

### `GET /api/characters/classes`

Route name `character.classes`. Access: **anyone**.

| Parameter | |
| --- | --- |
| `limit` | 1–50, default 8 |

A genuine `GROUP BY class` over the character table, ordered by popularity,
with names resolved server-side so the client carries no copy of rAthena's 153
job names.

```json
{
  "data": [
    { "job_id": 5, "job_name": "Merchant", "characters": 912 },
    { "job_id": 1, "job_name": "Swordsman", "characters": 704 }
  ]
}
```

An aggregate: no individual character is identifiable from it. Returns an empty
array on a server with no characters, rather than inventing classes to fill a
block.

---

## News

### `GET /api/news`

Route name `news.index`. Access: **anyone**. Paginated.

### `GET /api/news/{article}`

Route name `news.view`. Access: **anyone**.

FluxCP's news CMS, read from `cp_cmsnews`, newest first.

```json
{
  "data": [
    {
      "id": 12,
      "title": "Season opens",
      "excerpt": "Adventurers assemble at the gates of…",
      "author": "gamemaster",
      "link": null,
      "published_at": "2026-09-28T10:00:00+00:00",
      "updated_at": null
    }
  ]
}
```

**No category and no thumbnail.** The legacy `cp_cmsnews` schema has neither,
so returning them would mean inventing them.

The `excerpt` is derived from the body with tags stripped, because truncating
HTML at a character count produces unbalanced markup — and because a listing
that interpolated stored rich text unescaped is how a news CMS becomes an XSS
vector. The full `body` is returned **only** for a single article, where the
client renders it as HTML deliberately.

Read-only. The admin half of the legacy CMS — manage, add, edit, delete — is
not built, so there is no write path rather than a stub that looks like one.

---

## Realtime

Server status is also broadcast, so a client does not have to poll.

| | |
| --- | --- |
| Channel | `server-status` (public) |
| Event | `.server.status.updated` |
| Payload | Identical to `GET /api/server/status` |

The payload is deliberately the same shape, so a client applies a broadcast and
a poll through one code path and the fallback cannot drift from the live path.

```ts
Echo.channel('server-status').listen('.server.status.updated', (payload) => {
    // same shape as the REST response
})
```

A public channel is readable by anyone who knows its name, so it carries only
what the public status page already shows. **Anything account-scoped must use a
private or presence channel**, authorised in `routes/channels.php` (D14).

Broadcasting is optional. With Reverb unconfigured, or the socket unreachable,
the client falls back to polling at the interval `cache_seconds` reports.
