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

**Decision.** The 25 `cp_*` tables are expressed as Laravel migrations, with the ledger in the
database rather than on disk, and the per-request installer check is dropped — schema state is
a deployment concern.

Critically, the migrations keep each table **in the database the legacy installer put it in**:
18 in the login database and 7 in the char/map database. They are not relocated to a tidy
application-owned database, because the panel joins them directly against rAthena tables in the
same schema, for example in `account/index`:

```sql
LEFT OUTER JOIN {$server->loginDatabase}.cp_credits AS credits
             ON login.account_id = credits.account_id
```

Moving them would turn every such join into a cross-database join, which breaks outright as
soon as an operator does what the config explicitly invites and puts the logs or login database
on a separate host. Laravel's own tables (sessions, cache, queue) are a different matter and do
live on a separate application connection, since rAthena knows nothing about them.

**Why.** The disk ledger desynchronises from reality whenever `data/` is rebuilt, moved between
hosts or excluded from a backup, and the per-request check costs a filesystem walk on every
page view. The resolved end-state of all 44 legacy files was obtained by executing them in
version order against a throwaway database, so the migrations reproduce the real final schema
rather than a reading of it.

**Compatibility.** Each migration checks for the table before creating it and never drops or
rewrites an existing one, so pointing the port at a database that already carries a FluxCP
schema is safe and non-destructive.

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

## D7 — Themes are design tokens plus file-resolution overrides

**Legacy behaviour.** `themes/<name>/<module>/<action>.php` are PHP templates. A theme's
`manifest.php` may declare `inherit`, and `Flux_Template::themePath()` walks parent theme then
add-ons when a file is absent. The shipped `bootstrap` theme uses this to override only
`header.php`, `footer.php`, `main/navbar.php` and CSS — 4 files against the default theme's 144.

**Decision.** Three mechanisms, in increasing order of how much a theme takes over:

1. **Design tokens.** Core components read semantic CSS custom properties rather than a
   palette, so a theme shipping nothing but `variables.css` restyles the whole application.
   The `slate` theme is exactly that and nothing more.
2. **Block overrides.** A page is built from named sections — hero, navbar, server status,
   rankings, news, footer. Eleven ship in `resources/js/blocks/`, and a theme replaces one by
   putting a file of the same name in `resources/themes/<slug>/blocks/`. `fantasy` overrides
   eight and inherits three, so the fallback is exercised in production rather than only in a
   test.
3. **Page composition.** A theme declares a page in `theme.json` as a layout plus an ordered
   list of block names. Reordering the page is editing that list; adding or removing a section
   touches no component. `resources/js/pages/ComposedPage.vue` renders it and is a loop.

Whole pages, layouts and components can still be overridden by file resolution, which is the
same "override one file, inherit the rest" shape the legacy system had.

### This revises an earlier version of this decision

The first version of D7 recorded only the token system, and argued against file resolution on
the grounds that "keeping a path-walking template resolver on top of Vue would add a second,
weaker component system".

That reasoning was wrong about the cost. `import.meta.glob` resolves the override set **at
build time**, so there is no runtime path walking and no template interpretation: each theme's
files become ordinary lazy chunks, and an inactive theme's chunks are simply never requested.
The resolver is about forty lines and adds no component system at all.

It was also wrong about the need. Tokens handle recolouring, which is most themes. They cannot
restructure a masthead or lay a dashboard out differently, and that is precisely what the
legacy mechanism was for. Dropping it would have meant a theme could change a panel's colours
but not its shape.

**Why the ordering matters.** Each layer costs more maintenance than the one above it. A
palette cannot drift from the core as pages change; an overridden block drifts only if that
section changes; an overridden page is a fork of that page. Preferring the cheapest layer that
does the job is what keeps themes maintainable rather than abandoned.

**Why composition rather than only overrides.** Overriding a page to reorder its sections means
copying the whole page, and then the copy stops receiving improvements. A composition expresses
the same intent — these sections, this order — as data, so the blocks stay shared.

**What it deliberately is not.** Compositions have no conditionals, no nesting, no slots and no
per-block visibility rules. A section needing real logic should be a block, where it is
ordinary Vue with types and a test, rather than an expression language in JSON. The brief for
this work asked for a modular theme engine and explicitly not an overengineered CMS; that line
is where it was drawn.

**Guard rails, because an override is a fork of that file.**

- Shell behaviour — the navigation list, active-route matching, the sign-out sequence — lives in
  `useShell()`, and branding in `useGame()`. A theme's blocks consume them. Without this, every
  theme would copy the sign-out sequence and the fourth one would forget to clear the session.
- **Blocks receive data through core composables** (`resources/js/blocks/data.ts`), each
  returning a contract from `contracts.ts`. A block never calls the API. The contracts are
  shared, so a theme cannot invent its own backend shape — if it could, switching themes would
  mean changing the API and the backend would no longer be independent of the presentation.
- Themes may not fetch data or make authorisation decisions. A test scans every theme file for
  `DB::`, `Hash::`, `Gate::`, `fetch(` and the hardcoded game name, and another asserts the API
  returns byte-identical responses whichever theme is active.
- `npm run verify:themes` fails when an override is not in the resolver's glob, or when a
  composition names a block nothing provides. Both symptoms are otherwise silent: the core file
  is used, or the section is simply missing, with no error either way.

**What is preserved from the legacy system.** Override one file and inherit the rest; restyle
and replace chrome without forking every page; and a switchable theme — now an operator setting
(`APP_THEME`) rather than a per-session user choice, with light/dark remaining the visitor's.

**The one trade.** Switching between installed themes needs only `.env`, resolved per request.
Adding a *new* theme directory needs `npm run build`, because Vite must have seen the files. The
alternative — resolving theme files over HTTP at runtime — would mean shipping a template
interpreter to the browser and giving up bundling.

See [THEMING.md](THEMING.md).

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

**Decision.** The Vue client is a first-party SPA served from the same origin as
the API. It authenticates with Laravel's session cookie, and the API routes are
registered inside the **`web` middleware group** so they get encrypted cookies,
the session, and CSRF verification. No bearer token is issued to the browser.

**Why not a token.** A token held in JavaScript is readable by any successful
XSS; an `HttpOnly` cookie is not. The legacy panel was already cookie-session
based, with `httponly`, `samesite=Strict` and `secure` tied to its `ForceHTTPS`
setting, so this preserves its security properties rather than weakening them.

**Why not Sanctum.** Sanctum's SPA mode was implemented first and then removed.
`EnsureFrontendRequestsAreStateful::fromFrontend()` decides whether to attach
the session by inspecting the request's `Referer` or `Origin` header:

```php
$domain = $request->headers->get('referer') ?: $request->headers->get('origin');

if (is_null($domain)) {
    return false;
}
```

A request arriving without either header therefore silently loses its session
and appears unauthenticated. That is a surprising failure mode to accept in the
authentication path when the client and the API share an origin and the `web`
group does the job unconditionally. Nothing else in the application needed
Sanctum, and an unused dependency sitting in the authentication path is worse
than no dependency.

**What this gives up.** There is currently no token-based authentication for
third-party API consumers. Adding Sanctum's token guard later is additive and
does not disturb the session path, so it is left until a real consumer exists
rather than carried speculatively.

**Supporting measures**, neither of which the legacy panel had:

- The session id is regenerated on sign-in, which is what prevents session
  fixation.
- Sign-in is rate limited per account and, more loosely, per address.

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

---

## D13 — The War of Emperium schedule is evaluated correctly

**Legacy behaviour.** `Flux_Athena::isWoe()` resolved each configured window
with `strtotime()` against a day name, and compared the result to a Unix
timestamp:

```php
$serverTime = (int)$this->getServerTime();            // 'U' -- a Unix timestamp
$start = strtotime("$sDay {$woeDayTime['startingTime']}");
$end   = strtotime("$eDay {$woeDayTime['endingTime']}");

if ($serverTime > $start && $serverTime < $end) {
    return true;
}
```

Three things are wrong with it.

1. **Windows that cross midnight never match.** `strtotime()` resolves a day
   name relative to today, so for a window running Saturday 23:00 to Sunday
   01:00, `$end` lands *before* `$start` whenever today is a Saturday, and the
   `>` / `<` pair can never both hold. A 23:00 WoE simply does not register.
2. **The per-pair timezone is ignored.** `getServerTime('U')` formats as a Unix
   timestamp, which is an absolute instant and carries no timezone, so the
   `DateTimeZone` that `getServerTime()` carefully applies has no effect on the
   result. The window is really evaluated in PHP's default timezone.
3. **Malformed windows are discarded silently.** The config parser `continue`s
   past any entry whose day is out of range or whose time does not match
   `\d{2}:\d{2}`, so a typo disables that window — and with it the WoE access
   restrictions — with no error anywhere.

**Decision.** Windows are modelled as weekly recurring intervals in
`App\Support\Rathena\WoeWindow`, stored as minutes from the start of the week.
A window whose end is not after its start is understood to wrap through the end
of the week, the containment test is done in the pair's own timezone, and a
malformed window raises `InvalidArgumentException` at boot instead of vanishing.

**Why this is a fix and not a liberty.** The configuration already expresses
intent unambiguously — the legacy comments give `array(0, '12:00', 0, '14:00')`
with explicit start and end days — so a window spanning two days is clearly
meant to be supported. Reproducing the bug would mean the panel's WoE
restrictions silently fail to apply on exactly the schedules most servers use,
and those restrictions exist so players cannot scout castles from the website
during a siege.

**Observable difference.** A server with a WoE window crossing midnight, or one
whose char/map pair declares a timezone different from the web host's, will now
see the restriction applied when it previously was not. The window boundary is
also now half-open (start inclusive, end exclusive) rather than open at both
ends, so a check exactly at the start minute counts as in progress.

---

## D14 — Realtime is additive, and public channels carry only public data

**Legacy behaviour.** None. FluxCP had no realtime anything: the status page
cached to a file on disk and the only machine-readable feed was
`server/status-xml`, which a client had to poll.

**Decision.** Reverb broadcasting is added as a new capability, and the first
thing on it is server status, because that is the figure visitors reload the
panel for. Two rules govern it:

1. **A public channel carries only data the page already shows publicly.** The
   `server-status` channel carries whether each process answers a TCP
   connection and how many characters are flagged online — exactly what the
   public status page displays. Nothing about an individual account or
   character goes near it.
2. **Anything account-scoped uses a private or presence channel**, authorised
   server-side in `routes/channels.php`. A public channel is readable by anyone
   who knows its name, and channel names are guessable.

**Realtime is never the only path.** The client prefers a broadcast and falls
back to polling when broadcasting is not configured or the socket drops, and the
broadcast payload is shaped identically to the REST response so both are applied
through one code path and cannot drift apart. An operator who has not set up
Reverb gets a working status page, not a figure frozen at page load.

**Why the measurement moved to a schedule.** The legacy panel probed every
server from a hook that ran before every request, so page load time depended on
how long a firewalled port took to time out, multiplied by the number of
servers. A scheduled command measures once per cycle, repopulates the cache the
REST endpoint reads, and broadcasts the result, so the cost is fixed rather than
per visitor.

**Verified end to end**, not assumed: a WebSocket client connected to a running
Reverb server, subscribed to `server-status`, and received the dispatched event
with the expected payload.

---

## D15 — Recovery tokens are stored as digests, not as themselves

**Legacy behaviour.** All three recovery flows generated their code the same
way and stored it verbatim:

```php
$code = md5(rand());                        // create.php
$code = md5(rand() + $row->account_id);     // resetpass.php, changemail.php
```

That code then went into `cp_createlog.confirm_code`, `cp_resetpass.code` or
`cp_emailchange.code` exactly as it had been e-mailed.

**Two separate problems.** `rand()` is not a cryptographic generator, and the
account id it was added to is public, so the value was derivable rather than
guessed — a handful of observed codes narrows the seed. And because the stored
value *was* the e-mailed value, read access to those tables was equivalent to
the ability to reset any password and activate any account. A database dump, a
backup on a shared host, a read-only reporting user, a SQL injection anywhere
else in the application: each becomes account takeover.

**Decision.** `App\Support\Tokens\SecureToken` mints 256 bits from
`random_bytes()` and stores a digest. The e-mailed value is 64 hex characters;
the stored value is the first 32 characters of its SHA-256.

**Why truncated.** The three columns are all `varchar(32)`. Widening them would
break an existing FluxCP installation reading the same tables, which is the
same constraint as D1: the schema belongs to rAthena and FluxCP, and this panel
works within it rather than around it. 128 bits of a SHA-256 is far beyond what
a preimage attack reaches, so truncating costs nothing an attacker can use,
while storing the token itself costs everything.

**What this changes operationally.** Nothing can print a working link from the
database. Support staff cannot read somebody's confirmation code out of
`cp_createlog` and read it to them over the phone — they resend it instead.
That is the intended trade.

The token is also cleared, not merely flagged, once used, so a spent row does
not stay matchable.

---

## D16 — A password is never sent by e-mail

**Legacy behaviour.** `resetpw.php` generated a password, wrote it to the
account, and e-mailed it:

```php
$newPassword .= $characters[array_rand($characters)];   // alphanumerics only
...
$mail->send($acc->email, 'Password Has Been Reset', 'newpass',
    array('AccountUsername' => $acc->userid, 'NewPassword' => $unhashedNewPassword));
```

**Three things wrong with it.** The account's working credential existed as
readable text in a mailbox, in the sending server's queue, and in every relay
in between — indefinitely, because nobody deletes those mails. The password was
one the account holder never chose, so it was either kept (a server-generated
password in a mailbox) or changed immediately (making the mail pointless). And
the generated alphabet was alphanumeric only, so the result was weaker than the
policy the registration form enforces.

**Decision.** The reset link lets somebody choose their own password, and no
password appears in any outbound mail. The legacy `newpass` template is
replaced by `PasswordChangedMail`, which reports *that* the password changed,
with the time and originating address, and names nothing secret.

That replacement is not merely a removal. A notice is the only thing that makes
an unnoticed account takeover noticeable: somebody who did not make the change
finds out from it. It is sent to the address on the account rather than to
anything supplied with the request, so whoever made the change cannot also
decide who hears about it.

Tests assert that no mailable's rendered body contains the password.

---

## D17 — The staff password policy applies to staff

**Legacy behaviour.** `changepass.php` chose which policy to enforce like this:

```php
$useGMPassSecurity = $session->account->group_level < Flux::config('EnableGMPassSecurity');
$passwordMinLength = $useGMPassSecurity ? Flux::config('GMMinPasswordLength') : Flux::config('MinPasswordLength');
```

`EnableGMPassSecurity` is a group level. The comparison is `<`, so
`$useGMPassSecurity` is true when the account is **below** the staff threshold
— and the `GM*` settings were then applied to ordinary players, while game
masters got the ordinary ones. The setting did the opposite of what its name
says, in the direction that matters.

The same file then mixed the two sets when checking:

```php
elseif (Flux::config('PasswordMinUpper') > 0 && preg_match_all(...) < $passwordMinUpper)
```

The decision to enforce reads the player setting; the threshold compared
against is the GM one. With `PasswordMinUpper` at 0 and `GMPasswordMinUpper` at
2, the requirement is silently skipped.

**Decision.** `panel.registration.password.staff` is merged over the player
policy for accounts at or above `applies_at_or_above_level`, so an operator
states only what differs, and the stricter set goes to the more privileged
accounts. One resolver (`RathenaAccountService::passwordPolicy()`) decides it,
and the same policy applies on registration, on a password change and on a
reset.

This is recorded as a decision rather than a bug fix because reversing it
changes behaviour an operator may have come to rely on: on a legacy install,
players were held to the GM rules. Anyone migrating a customised
`application.php` should expect their player policy to loosen and their staff
policy to tighten — both toward what the settings claim.

---

## D18 — A password change ends other sessions, not your own

**Legacy behaviour.** `changepass.php` finished like this:

```php
$session->setMessageData(Flux::message('PasswordHasBeenChanged'));
$session->logout();
$this->redirect($this->url('account', 'login'));
```

It signed the account holder out of the session they were using, and did
nothing about any other session.

**Why that is backwards.** A password change is most often a response to
suspecting somebody else has access. The legacy behaviour inconveniences the
one person it should not and leaves the attacker's session running until it
expires on its own — so the change achieves nothing against the case that
prompted it.

**Decision.** The account holder keeps the session they are using, with its id
regenerated, and every other session for that account is deleted.
`SessionRegistry` does it by removing rows from the session table.

A completed password *reset* passes no exception, so every session ends:
whoever is signed in as that account at that moment is the problem the reset is
solving.

**The limitation is reported, not hidden.** This works with the `database`
session driver, the default. With `file` or `cookie` there is nothing to query,
so `SessionRegistry` returns false and the endpoint says
`other_sessions_revoked: false`. Claiming otherwise would be a false
reassurance to somebody who has just changed their password because they think
it was stolen.

---

## D19 — Two CAPTCHA drivers, and neither loads a third-party script by default

**Legacy behaviour.** Two booleans, `UseCaptcha` and `EnableReCaptcha`,
encoding one three-way choice. The native path used GD with a TrueType font;
the reCAPTCHA path did this:

```php
$response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".
    Flux::config('ReCaptchaPrivateKey')."&response=".$_POST['g-recaptcha-response']."&remoteip=".$_SERVER['REMOTE_ADDR']);
$responseKeys = json_decode($response, true);
if (intval($responseKeys["success"]) !== 1) { ... }
```

No timeout, so a slow reply hung the request for however long PHP's socket
default was. No error handling, so a network failure was a warning followed by
`json_decode(false)` returning null. And the secret and the user's response
token both went into a URL, where they land in proxy and server logs.

**Decision.** One `ChallengesHumanity` contract with two implementations,
selected by `PANEL_CAPTCHA_DRIVER`. An unrecognised name throws at resolution
rather than falling back — falling back to "no challenge" would turn a typo in
`.env` into open registration.

**The self-hosted driver** draws with GD's built-in fonts rather than a
TrueType file, so there is no font to ship, licence or lose; the distortion
comes from per-glyph rotation and scaling. Its alphabet excludes `0/O/1/I/L`,
because a challenge somebody cannot read stops registrations rather than
robots. The answer is stored in the session as a SHA-256 — the session store is
a database table here, and a table of readable CAPTCHA answers is a thing worth
not having. A challenge expires, and is consumed when checked **including on a
wrong answer**, so one image cannot be brute-forced. The legacy challenge sat
in the session indefinitely and was not cleared on use, so a solved image could
be replayed for the life of the session.

**The reCAPTCHA driver** posts its parameters instead of interpolating them
into a URL, sets a connect and a total timeout, and **fails closed**. Treating
"cannot reach the verifier" as "human" would turn an outage at Google into open
registration here.

**The stated limit.** The panel does not load third-party scripts on its own
account, so with `recaptcha` selected the client cannot render the widget;
`CaptchaField` says so rather than drawing a box that cannot be answered. An
operator who wants reCAPTCHA has to add its script themselves. This is a
deliberate limit of the port, written down rather than discovered.

**Honest about what it is worth.** Any hand-rolled image CAPTCHA is solvable
with off-the-shelf OCR. The self-hosted driver exists to stop casual scripted
registration, and the class comment says so. Rate limiting, which the legacy
panel had none of, is the control that actually bounds abuse here.

---

## D20 — Operator-authored content is Markdown, not raw HTML

**Legacy behaviour.** The news and static-page templates echoed their body
columns into the page with no escaping:

```php
<?php echo $news->body ?>
<?php echo $page->body ?>
```

Whatever an administrator typed into the editor was executed in every
visitor's browser.

**Why this is worse than it sounds.** It is stored cross-site scripting, and
the editor is reachable by *every* administrator rather than only by whoever
set the server up. One careless or compromised staff account reaches every
player who loads the front page, and the payload persists until somebody
notices and edits the row. On a panel whose whole job is handling game
accounts, the thing an injected script would most usefully steal is a session.

**Decision.** Bodies are stored exactly as typed and rendered through
`ContentRenderer` for display: Markdown, with raw HTML **stripped** and unsafe
link schemes refused.

The API serves both forms. `body` is the operator's text, unchanged, for the
editor. `body_html` is the rendered, safe version, and is the only one the
client displays. Nothing in the database is rewritten, so an existing FluxCP
install's content is left exactly as it was found.

`news.link`, which the listing renders as an anchor, is restricted to `http`
and `https` for the same reason — with the body handled, the link field would
otherwise be the remaining way in.

**The cost, stated plainly.** Content already written as HTML renders with its
tags removed rather than as formatted markup. An operator migrating from
FluxCP will want to rewrite those entries as Markdown; the raw body is
preserved, so nothing is lost while they do.

**Why not allow a safe subset of HTML.** That means shipping a sanitiser and
maintaining an allow-list of tags and attributes. Allow-lists of this kind fail
quietly — a missed attribute, a new HTML feature, an SVG payload — and the
failure is invisible until somebody exploits it. Markdown with HTML off has a
much smaller surface, and `league/commonmark` already ships with Laravel, so
it adds no dependency.

**Also fixed here.** The legacy stored a page's `path` as typed and looked it
up with an exact match, so a page whose link and row disagreed about a trailing
slash or a capital letter was simply unreachable. Paths are normalised to
lower case without surrounding slashes, and validated to letters, digits,
dashes and slashes — which also refuses anything that could be read as a
traversal or a query string.

---

## D21 — The log browsers are declarations, and each carries its own level

**Legacy behaviour.** 22 modules — `cplog/ban`, `cplog/login`, `logdata/pick`,
`logdata/zeny` and the rest — each a file repeating the same sequence: count
the rows, build a paginator, set its sortable columns, fetch a page, render a
table. All 22 were `AccountLevel::ADMIN` in `config/access.php`.

**The problem with 22 copies** is not the duplication itself. It is that they
drift: the sortable column lists, the date filtering and the ordering
defaults were already inconsistent between them in the version ported here,
because a fix applied to one was not applied to the others.

**Decision.** The views are declarations in `config/log_browsers.php` — table,
connection, columns with labels and types, filterable columns, date column,
level — and one controller reads any of them. Adding a log view is an entry in
that file.

**Columns are intersected with the real schema.** A view declares the columns
it would like, and only the ones the table actually has are selected. This is
not defensiveness for its own sake: rAthena's log schema varies by version,
and *which tables exist at all* depends on what the operator enabled in
`log_athena.conf`. A hardcoded `SELECT` turns "this server logs slightly
differently" into a 500 on an admin page, and a missing table turns "we do not
log that" into one as well. A view whose table is absent reports
`available: false` with a sentence saying so.

**The levels are not loosened.** Every view is `Administrator`, which is what
the legacy had for all 22. Widening who can read the logs is an operator's
decision, not a migration's, and an earlier draft of this work had several
views at junior and senior level before that was caught — the levels here are
the legacy ones, deliberately.

**Why per view rather than one setting.** So the decision can be made view by
view. The item and zeny logs are everyday moderation; the chat log is every
private message players have sent each other, and the transaction log is
payment data. An operator who wants junior staff handling item disputes can
lower those two views without also handing over the chat log.

For that lever to work, the *route* floor is `JuniorGameMaster` while the
*view* level is `Administrator`. The route being held at Administrator would
refuse a junior staff member before the per-view level was ever consulted.
Default behaviour is identical to the legacy; the floor only exists so that
lowering a single view has an effect.

**`cp_resetpass.code` is not among the columns of the password-reset view.** It
is a digest rather than a working token (D15), but a log browser has no reason
to show it, and listing it would put it in every administrator's browser
history.

---

## D22 — The account search cannot search by password

**Legacy behaviour.** `modules/account/index.php` accepted a `password`
parameter and matched it against `login.user_pass`:

```php
$password = $params->get('password');
...
$sqlpartial .= "AND user_pass = ? ";
```

**Why that is worse on this schema than it would be elsewhere.** `user_pass` is
cleartext, or unsalted MD5 when the server runs with `use_MD5_passwords` (D1).
A search over it is therefore:

- a way to find every account sharing a password, by typing a common one;
- a way to confirm a guess against the entire player base in one request,
  rather than one account at a time through a rate-limited sign-in form;
- available to anyone who can open the admin search, which on a typical server
  is more people than can read the database.

It is an oracle that turns one leaked or guessed password into a list of every
account using it.

**Decision.** The filter is not ported, and `login.user_pass` is not among the
columns the search selects, so there is nothing for a response to leak by
accident. Every other filter the legacy screen had — account id, name, e-mail,
last address, gender, state, group, login count, and the last-login and
birthdate ranges — is present.

This is consistent with what FluxCP's own access map already said: the related
`SearchCpChangePass` ability was `NOONE`, which is the upstream project
reaching the same conclusion about the neighbouring feature and leaving this
one in place.

**Rank protection on the edit screen** is recorded here too, because it is the
other half of the same problem. Staff may not edit an account at or above their
own rank, and may not grant a rank they do not themselves exceed. Without both,
the lowest-ranked person who can open the edit screen promotes themselves to
administrator in two steps and the permission ladder is decorative. FluxCP had
the idea as the `EditHigherPower` ability, defaulted to `NOONE`; it is enforced
here rather than being a flag nobody turns on.

Nor can the screen set a password. The legacy edit form did not offer that
either, and it should not: an administrator helping somebody locked out
triggers a reset rather than choosing a credential they then know. The columns
the emulator records — `logincount`, `lastlogin`, `last_ip` — are not editable
either, because rewriting them is falsifying an audit trail.

---

## D23 — Web commands need an allow-list

**Legacy behaviour.** `modules/webcommands/index.php`, in full:

```php
$sql = "INSERT INTO {$server->charMapDatabase}.$tbl (command, issuer, account_id) VALUES (?, ?, ?)";
$sth->execute(array($_POST['command'], $session->account->userid, $session->account->account_id));
```

The submitted string went into `cp_commands` unchanged. That table is polled by
an rAthena script which executes what it finds **with game-master powers** —
that is the point of the feature; it is how a website button runs `@refresh`
for a stuck player.

**Why that is the most dangerous write in the panel.** There is no validation
and no allow-list, so anyone who can reach the page can queue anything the
script will run. `@item 501 30000`, `@zeny 2000000000`, `@adjgroup 99`. The
permission map holds the action at `NORMAL`, so that is every signed-in player.

The damage is not even bounded by the panel's own permissions, because the
script runs as staff regardless of who queued the row.

**Decision.** Off unless the operator turns it on, and a command is queued only
if it matches one of the patterns they configured. Patterns are shell globs, so
`@refresh` is one command and `@storage*` a family.

**An empty allow-list accepts nothing.** Turning the feature on without
configuring it leaves it inert rather than open — the opposite of the usual
"empty means unrestricted" convention, chosen because the failure mode of
getting this wrong is a player with administrator powers in the game.

The issuer and account id come from the session rather than the body, as
everywhere else in this port, so the audit row cannot name somebody else.

**Also capped here:** credit transfers between players. FluxCP allowed any
amount, which makes the panel a laundering route — buy credits on one account,
move them to another, and the trail of who paid is broken.
`PANEL_CREDIT_TRANSFER_MAX` is a brake, and `enabled` turns the feature off
entirely. Transfers to your own account are refused, which the legacy did not
check.

## D24 — Game artwork is not fetched from third-party sites

FluxCP's `DivinePrideIntegration` downloaded item and monster images from
divine-pride.net when a local copy was missing, and `ItemIconNameFormat`,
`ItemImageNameFormat`, `JobImageNameFormat` and `MonsterImageNameFormat` named
the local files it looked for first.

**None of this is ported, and the omission is the decision rather than an
oversight.** Those images are Ragnarok Online client artwork. This project has
no licence to redistribute them, and a panel that fetches them on demand
redistributes them from whatever server it is installed on — it is the same act
as shipping them, performed later and by the operator.

What is ported is the part that is the operator's own: an item image an
operator uploads for their credit shop is served from the configured disk, as
`panel.item_shop.image_disk`. That is their file and their decision.

An operator who holds the rights to the client artwork can serve it from that
same disk. The panel does not go and get it for them, and there is no
configuration flag that makes it.

**Guild emblems are the one image the panel does decode**, because an emblem is
not game artwork: it is a bitmap a player uploaded to a server the operator
runs, stored in that server's own `guild.emblem_data`. FluxCP's
`EmblemUseWebservice`, `ForceEmptyEmblem` and `MissingEmblemBMP` are not
ported with it. The first fetched emblems from a remote service rather than
decoding the column, which is a network call on a page render for data already
in the database; the other two substituted a placeholder image. A guild with no
emblem is a 404 here, so the client decides whether to draw a fallback — a
placeholder served with a 200 cannot be told from a real emblem by a cache.

## D25 — Presentation stays out of the API

Several FluxCP options set colours and markup from configuration:
`StaffReplyColour`, `FontPendingColour`, `FontResolvedColour`,
`FontClosedColour`, `AdminMenuNewStyle`, and `ShowRenderDetails`.

**These are not ported as configuration.** A ticket's status reaches the client
as `"Resolved"`, not as a colour, and what colour a theme paints it is the
theme's business. Porting them would put presentation in the API and give an
operator a second, worse place to style the panel from — one that no theme
could override, which is the opposite of what the theme system is for.

`ShowCopyright` is not ported either: the LGPL-3.0 attribution stays. It is not
a display preference.

## D26 — A search with one result does not redirect

`SingleMatchRedirect`, `SingleMatchRedirectItem` and `SingleMatchRedirectMobs`
sent a search with exactly one result straight to that result's page.

**Not ported as server behaviour.** The endpoints return a list of one, because
that is what was asked for, and a 302 from a JSON endpoint to an HTML page is
not something a client can sensibly follow. The behaviour itself is worth
having and belongs in the client, where it is a navigation decision made with
the response in hand.

Recorded here so it is a decision about where the behaviour lives rather than
a feature that went missing.

## D27 — A lapsed ban is interpreted, not tidied

FluxCP's `AutoRemoveTempBans` ran `UPDATE login SET unban_time = 0 WHERE
unban_time <= UNIX_TIMESTAMP()` whenever an administrator opened the account
search.

**Not ported.** `Account::isTemporarilyBanned()` compares the timestamp against
the current time, so a lapsed ban reports false without anything being written.
The legacy put a write on a read path and made whether a ban had been cleared
depend on whether somebody had happened to visit a page; rAthena compares the
timestamp against the current time as well, so a stale value is not acted on by
the emulator either.

An explicit unban still clears the column, because that is a deliberate act
with an audit row behind it.

## D28 — The panel does not police its own file ownership

FluxCP's `RequireOwnership` made `index.php` refuse to run unless the executing
user owned `FLUX_ROOT/data/`, and `Flux_PermissionError` carried the `chown` and
`chmod` commands to fix it.

**Not ported.** There is no equivalent directory — the panel writes to
`storage/`, which Laravel owns and which a deployment configures once — and a
running application checking `posix_geteuid()` against a directory's owner on
every request is a deployment concern answered at the wrong time. It also
cannot be made to work in the places this is likely to run: a container, a
shared host, or anything where the web user and the deploying user differ by
design.

The requirement itself is real and belongs in the deployment documentation,
where `storage/` and `bootstrap/cache` permissions are already covered.

---

This is the last of the legacy's 199 configuration options to be accounted for.
Every other one is either ported to `config/panel.php`, answered by Laravel's
own configuration, recorded as a decision above, or carried in
`config/rathena_reference.php`. The reconciliation is a script rather than a
reading, and worth re-running rather than trusting this paragraph:

```
rg -o "Flux::config\('([A-Za-z][\w.]*)'\)" -r '$1' legacy --glob '!legacy/config/**' | sort -u
```
