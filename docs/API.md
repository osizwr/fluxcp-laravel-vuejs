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
