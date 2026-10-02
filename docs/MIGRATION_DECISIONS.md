# Migration Decisions

Every decision that changes behaviour relative to legacy FluxCP, with the reason and the
evidence behind it. Anything not recorded here is intended to behave as the legacy panel does.

Each decision has a stable ID (`D1`, `D2`, …) referenced from the other documents.

---

## D1 — The rAthena password column is left exactly as rAthena expects it

**Legacy behaviour.** `Flux_LoginServer::isAuth()` compares `login.user_pass` directly:

```sql
SELECT userid FROM login
 WHERE sex != 'S' AND group_id >= 0
   AND LOWER(userid) = LOWER(?)
   AND user_pass = ?
 LIMIT 1
```

`Flux::hashPassword()` is `return md5($password);`, applied only when the server's
`LoginServer.UseMD5` flag is on. Otherwise the column holds cleartext.

**Decision.** The panel keeps writing `login.user_pass` in whatever format the configured
server uses — cleartext or unsalted MD5 — and keeps verifying against it.

**Why this is not negotiable.**

1. rAthena's own login server authenticates against that column. The panel is not its only
   consumer, so re-hashing it would stop every player logging into the game.
2. The column is `varchar(32)`. A bcrypt hash is 60 characters and an Argon2id hash is longer
   still; neither fits, so "just hash it" is not available without an rAthena-side schema
   change this project has no authority to make.
3. On the database inspected during the audit, stored password lengths were 2, 4, 6 and 16
   characters — the install runs with `UseMD5` off, i.e. cleartext.

**What is done instead.** Panel sessions do not rely on the rAthena value as their credential
of record. On a successful login the submitted password is also hashed with Laravel's default
hasher into an application-owned table, and subsequent panel logins verify against that hash
first, falling back to the rAthena comparison only when no panel hash exists yet. This gives a
transparent per-account upgrade without touching `login.user_pass`, and means a dump of the
application's own tables does not reveal game passwords.

**Residual risk, stated plainly.** While rAthena stores cleartext, a database compromise
exposes game passwords regardless of what the panel does. That is a property of the emulator's
schema, not of this port. Operators who want it fixed must enable `UseMD5` (weak, but not
cleartext) or widen the column and patch rAthena.

---

## D2 — Passwords are no longer written to the audit tables

**Legacy behaviour.** Four tables keep their own copy of the password:

| Table | Column(s) | Written on |
| --- | --- | --- |
| `cp_createlog` | `user_pass` | every registration |
| `cp_loginlog` | `password` | every CP login attempt, successful or not |
| `cp_pwchange` | `old_password`, `new_password` | every password change |
| `cp_resetpass` | `old_password`, `new_password` | every password reset |

With cleartext storage (the common case, per D1) a stock FluxCP writes every submitted
password — including failed attempts and therefore typo'd passwords from other sites — into
the database in the clear. The legacy mitigation is presentational only: `access.php` pins
`SeeCpLoginLogPass`, `SeeCpResetPass`, `SeeCpChangePass`, `SearchMD5Passwords` and
`SeeAccountPassword` to `NOONE`, hiding the columns in the UI while the data stays.

**Decision.** The port keeps these tables and their audit value, but **never writes a password
into them**. The columns are retained so an existing database stays readable, and are written
as empty. Audit rows still record who, when, from which IP, and whether the attempt succeeded.

**Consequence.** The five `NOONE` feature permissions above become vestigial: there is nothing
left to reveal. They are kept in the permission map so that an operator pointing the panel at a
legacy database with historical rows still cannot surface them through the UI.

---

## D3 — Unknown actions are denied instead of allowed

**Legacy behaviour.** `Flux_Authorization::actionAllowed()` returns `-1` when the requested
`module.action` has no entry in `access.php`, and `Flux_Dispatcher` only blocks when the result
is **strictly `false`**:

```php
if ($auth->actionAllowed($moduleName, $actionName) === false) { /* deny */ }
```

`-1` is not `false`, so an unlisted action is served to anyone. Ten shipped actions are in this
state:

`buyingstore/index`, `buyingstore/viewshop`, `errors/missing_action`, `errors/missing_view`,
`forum/index`, `ranking/alchemist`, `ranking/blacksmith`, `ranking/homunculus`,
`unauthorized/index`, `woe/custom`.

**Decision.** The port fails closed. A route with no explicit permission is denied, and the
permission map is the single source of truth.

**Compatibility.** Each of the ten actions above is given an explicit permission matching its
evident intent, so externally the behaviour is unchanged:
the three rankings and `buyingstore/*` and `woe/custom` become `ANYONE`, `unauthorized/index`
and the two `errors/*` pages stay reachable, and `forum/index` is dropped (see D10).

---

## D4 — Laravel migrations replace the file-ledger schema installer

**Legacy behaviour.** `data/schemas/<db>/<table>.<version>.sql` holds a `CREATE TABLE` plus
per-version deltas, each delta wrapped in a disposable stored procedure with a
`CONTINUE HANDLER FOR 1060` so re-running is harmless. Which versions have been applied is
recorded **as files on disk** under `data/logs/schemas/…`, and `main/preprocess` checks
`updateNeeded()` on *every request*, force-redirecting to the installer when anything is
outstanding.

**Decision.** The 25 `cp_*` tables are expressed as Laravel migrations on a dedicated
connection, with the ledger in the database (`migrations`) rather than on disk. The
per-request installer check is dropped; schema state is a deployment concern.

**Why.** The disk ledger desynchronises from reality whenever `data/` is rebuilt, moved between
hosts or excluded from a backup, and the per-request check costs a filesystem walk on every
page view. The resolved end-state of all 44 legacy files was obtained by executing them in
version order against a throwaway database, so the migrations reproduce the real final schema
rather than a reading of it.

**Compatibility.** Migrations use `CREATE TABLE IF NOT EXISTS` semantics and do not drop or
rewrite existing `cp_*` tables, so pointing the port at a database that already has a FluxCP
schema is safe.

---

## D5 — Database connections are registered at runtime

**Legacy behaviour.** `config/servers.php` is a list of server groups, each with its own
`DbConfig`, `LogsDbConfig`, `WebDbConfig`, one login server and one-or-more char/map pairs.
Queries interpolate the database name directly (`{$server->logsDatabase}.picklog`), and the
active group is a per-session choice driven by the `preferred_server` parameter.

**Decision.** Server groups are declared in configuration and their connections are registered
with Laravel's database manager at runtime, with the active group resolved per request from the
session. Queries address a *connection*, not an interpolated database name.

**Why.** `config/database.php` is static, and the number of connections is not known until
configuration is read. Interpolating database names into SQL also makes cross-database joins
implicit and unportable; naming connections makes the boundary explicit and lets the logs
database genuinely live on another host, which is the documented reason it is configured
separately.

---

## D6 — The item/monster temporary-table merge is preserved

**Legacy behaviour.** `Flux_TemporaryTable` creates a MySQL `TEMPORARY` table per request and
populates it from `item_db`, `item_db_re`, `item_db2`, `item_db2_re` in order, each table
destructively overriding rows already present, so custom and renewal entries win. The same is
done for the `mob_db` family. Eleven actions depend on it.

**Decision.** The override semantics are preserved exactly. The merge is implemented as a
dedicated service rather than being open-coded in eleven places.

**Why.** This is the kind of obscure behaviour that is easy to drop and expensive to debug: a
port that queries `item_db` directly looks correct, passes a smoke test on a vanilla install,
and silently serves pre-renewal stats while ignoring every custom item on a real server.

---

## D7 — Theme inheritance becomes a design-token system

**Legacy behaviour.** `themes/<name>/<module>/<action>.php` are PHP templates. A theme's
`manifest.php` may declare `inherit`, and `Flux_Template::themePath()` walks parent theme then
add-ons when a file is absent. The shipped `bootstrap` theme uses this to override only
`header.php`, `footer.php`, `main/navbar.php` and CSS — 4 files against the default theme's 144.

**Decision.** PHP template inheritance cannot survive the move to a Vue SPA and is replaced
rather than emulated. Customisation is provided through CSS custom properties (design tokens),
replaceable Vue layout components, and slot-based page shells.

**Why.** The legacy mechanism is file-resolution-based: it exists so a theme can replace one
server-rendered page without copying the other 143. With a component tree the equivalent need
is met by overriding a component or a token, and keeping a path-walking template resolver on
top of Vue would add a second, weaker component system.

**What is preserved.** The capability the theme system actually delivers — restyle and replace
chrome without forking every page — and the per-session theme switch, which becomes a
persisted user preference.

---

## D8 — Add-ons become Laravel packages

**Legacy behaviour.** `addons/<name>/` may contribute `config/addon.php`, `config/access.php`,
`modules/`, `themes/` and `lang/`. `Flux_Addon` discovers them, their access config is merged
into the global map at boot, and `themePath()` falls through to add-on template directories.
One example add-on (`helloworld`) ships.

**Decision.** Extension is provided through Laravel's package mechanism: service providers for
registration, route/config/migration/translation merging through the framework's own publishing
and merge facilities, and permission contributions through the same permission registry the core
uses.

**Why.** Every capability the add-on loader provides — discovery, config merge, routes, views,
translations — is something Laravel's package system already does, with autoloading, versioning
and dependency resolution the legacy loader lacks. Keeping a parallel loader would mean
maintaining two extension systems.

**Status.** The permission registry is designed for third-party contribution. A worked example
package is not part of the current tree; this is recorded as outstanding in the matrix rather
than claimed.

---

## D9 — The project is licensed LGPL-3.0, matching upstream

FluxCP is distributed under the **GNU Lesser General Public License v3.0**
(`legacy/LICENSE`). This port is a derivative work in substance: it reproduces FluxCP's
database schema, permission model, configuration surface and behaviour, and was written by
reading its source.

**Decision.** The project carries LGPL-3.0 and preserves upstream copyright and attribution.
No copyright is claimed over the parts derived from FluxCP, and no third-party notice is
removed.

**Not a decision this project can make alone.** The copyright holder for the new code, and the
name that appears in author metadata, must be supplied by the project owner. Author fields are
left unset rather than filled with a guess.

---

## D10 — Two latent legacy defects are fixed, not reproduced

Both were found by diffing `access.php` against the actual action files and template usage.

**1. `ranking` permission keys do not match the shipped actions.**

```
access.php keys : bowman, character, death, guild, homun, mvp, spearman, swordman, zeny
actual files    : alchemist, blacksmith, character, death, guild, homunculus, mvp, zeny
keys with no file : bowman, homun, spearman, swordman
files with no key : alchemist, blacksmith, homunculus
```

`bowman`, `spearman` and `swordman` are leftovers from job-specific ladders that no longer
exist, and `homun` was renamed to `homunculus` without updating the key. The three real
rankings are consequently unlisted and fall through to "allowed" by D3's legacy quirk.

*Fixed:* the dead keys are dropped and the three real rankings get explicit `ANYONE`
permissions — the evident intent, and the behaviour users see today.

**2. The donate button never renders.**

`themes/default/account/view.php:71` gates it on `$auth->allowedToDonate`, which resolves to
`featureAllowed('Donate')`. No `Donate` key exists in `access.php`'s `features` map, so the
check always returns `false`:

```php
<?php if ($auth->allowedToDonate && $isMine): ?>
```

*Fixed:* a `Donate` permission is defined. Donation remains independently gated on whether
PayPal is configured, so enabling it does not expose a broken flow.

---

## D11 — Session authentication, not API tokens

**Decision.** The Vue client is a first-party SPA served from the same origin as the API, and
authenticates with Laravel's session cookie via Sanctum's SPA mode, with CSRF protection.
Bearer tokens are not issued to the browser.

**Why.** A token held in JavaScript is readable by any successful XSS; an `HttpOnly` cookie is
not. The legacy panel is already cookie-session based, with `httponly`, `samesite=Strict` and
`secure` tied to `ForceHTTPS`, so this preserves its security properties rather than weakening
them. Token-based auth remains available for genuine third-party API consumers.

---

## D12 — Configuration splits by audience

**Legacy behaviour.** `config/application.php` holds 361 options in one array, covering
framework concerns (timeouts, timezone, debug), deployment concerns (base URI, HTTPS,
credentials), operator policy (password rules, rates, feature toggles) and content (terms of
service text). `config/import/` allows local overrides.

**Decision.** They are separated by who owns them:

| Kind | Where it goes |
| --- | --- |
| Secrets and per-environment values | `.env` |
| Deployment and framework settings | `config/*.php` |
| Operator policy an admin should change without a deploy | database-backed settings, editable in the admin UI |
| Per-server-group values (rates, WoE times, ports) | server-group configuration |

**Why.** The legacy file mixes credentials with editable copy, so changing the terms of service
means editing a file that also contains the database password. Splitting by audience is what
makes "never hardcode secrets" enforceable rather than aspirational.
