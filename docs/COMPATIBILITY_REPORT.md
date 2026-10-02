# Compatibility Report

Differences between this port and legacy FluxCP, established by comparison
rather than by inspection. Each entry says how it was checked.

Behaviour that was deliberately changed is recorded in
[MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) and cross-referenced here by
decision id. Work that has not been done yet is **not** in this document — it
is in [FLUXCP_MIGRATION_MATRIX.md](FLUXCP_MIGRATION_MATRIX.md). This file
covers only what has been built and compared.

---

## 1. Panel-owned schema

**How this was checked.** All 44 of FluxCP's versioned schema files were applied
in version order to a throwaway database (`fluxcp_ref`), resolving the real end
state of the legacy schema. The port's installer was then run against a second
empty database (`fluxcp_blank`), and the two were compared column by column.

**Result.** 25 tables, 196 columns compared, 11 differences — all of them in
column types, none in table names, column names, defaults, nullability, or the
set of tables. Covered by `tests/Feature/Schema/PanelSchemaInstallTest.php`.

The comparison is **case-sensitive on type definitions and includes column
defaults**. It did not start that way, and the first version masked a real
defect: it lowercased both sides before comparing, so `enum('M','F','S')` and
`enum('m','f','s')` looked identical. The schema generator had been lowercasing
enum values, which meant the installer created `cp_createlog.sex` as
`enum('m','f','s')` with a default of `'m'`. MariaDB's case-insensitive
collation hid the consequence — inserting `'M'` silently stored `'m'` — so the
column would have read back lowercase to FluxCP and rAthena.

Both the generator and the comparison are fixed, and the installed value is now
verified as `'M'` after a real account creation. The lesson is the one worth
keeping: **a verification that normalises away a difference cannot detect that
difference**, and this one was normalising exactly the field it needed to check.

### 1.1 Auto-increment keys are unsigned

| Table | Column | Legacy | Port |
| --- | --- | --- | --- |
| `cp_cmsnews` | `id` | `int(11)` | `int unsigned` |
| `cp_cmspages` | `id` | `int(11)` | `int unsigned` |
| `cp_commands` | `id` | `int(6)` | `int unsigned` |
| `cp_emailchange` | `id` | `int(11)` | `int unsigned` |
| `cp_loginlog` | `id` | `int(11)` | `int unsigned` |
| `cp_pwchange` | `id` | `int(11)` | `int unsigned` |
| `cp_resetpass` | `id` | `int(11)` | `int unsigned` |
| `cp_servicedesk` | `ticket_id` | `int(6)` | `int unsigned` |
| `cp_servicedeska` | `action_id` | `int(6)` | `int unsigned` |
| `cp_servicedeskcat` | `cat_id` | `int(3)` | `int unsigned` |

Laravel's `increments()` produces an unsigned integer. The legacy files declared
some of these signed, and the display widths (`int(6)`, `int(3)`) were never
constraints — MySQL ignores them for storage, and newer servers drop them
entirely.

**Impact: none.** An auto-increment primary key is never negative, so the
signed range below zero was unusable. Unsigned doubles the usable key space.

**Existing databases are unaffected**, because the installer never alters a
table that already exists — asserted by
`it_leaves_an_existing_table_and_its_data_alone`.

### 1.2 `cp_credits.last_donation_amount` is wider

| Legacy | Port |
| --- | --- |
| `float unsigned` | `double` |

Laravel's `float()` maps to `double` on MySQL. A `double` holds every value a
`float` can and more precisely, so no stored amount can fail to round-trip.
Dropping `unsigned` permits a negative, which is meaningless for a donation
total but is not written by any code path.

**Worth noting for the future:** neither type is right for money. A refund or
reconciliation feature should move this to `decimal`, which is a schema change
affecting an rAthena-adjacent table and so needs an operator migration rather
than a silent alteration.

### 1.3 Password columns are created but never written

`cp_createlog.user_pass`, `cp_loginlog.password`,
`cp_pwchange.old_password`/`new_password` and
`cp_resetpass.old_password`/`new_password` are created so that an existing
FluxCP database remains readable and a legacy install can continue to run
alongside. This port never writes to them (**D2**).

Asserted by `it_never_writes_a_password_into_the_login_audit_table`.

---

### 1.4 `cp_createlog.sex` enum case

Fixed, not a difference any more. Recorded above because the masking is the
instructive part.

### 1.5 The rAthena credential format (verified against rAthena)

**How this was checked.** Previously this had been established from FluxCP's side
only (`Flux::hashPassword()` is `md5()`). It has now been confirmed against
**rAthena's own source**, which is the authority:

| Source | Says |
| --- | --- |
| `src/common/mmo.hpp` | `#define PASSWD_LENGTH (32 + 1)` |
| `src/login/account.hpp` | `char pass[32+1];  // 23+1 for plaintext, 32+1 for md5-ed passwords` |
| `src/login/login.cpp` `login_check_password()` | `return 0 == strcmp( sd.passwd, acc.pass );` when the client sends an unencrypted password |
| `src/login/login.cpp` `login_check_password()` | under `passwordencrypt`, MD5s **`acc.pass` itself** together with a per-session key |
| `src/login/login.cpp` `login_mmo_auth_new()` | `safestrncpy(acc.pass, pass, sizeof(acc.pass))` — stores the client-supplied password verbatim; the server never hashes it |
| `src/login/login.cpp` | `login_config.use_md5_passwds = false` is the default |

**Conclusion.** `login.user_pass` must contain exactly the string the client
transmits: the password itself by default, or its lowercase 32-character MD5
digest when the server runs with `use_MD5_passwords: yes`.

A bcrypt or Argon hash is not merely weaker-than-ideal here, it is impossible:
at 60+ characters it does not fit the column or the emulator's buffer, it is not
reproducible by the client, and under `passwordencrypt` rAthena would hash the
hash. An account created that way could never log in to the game.

Both formats are asserted end to end in
`tests/Feature/Rathena/RagnarokPasswordStorageTest.php`, and
`RagnarokAuthenticationCompatibilityTest` reproduces `strcmp()` to assert the
stored value satisfies the emulator's own comparison.

## 2. Authentication

**How this was checked.** `Flux_SessionData::login()` and
`Flux_LoginServer::isAuth()` were read line by line and each branch turned into
a test asserting the specific refusal reason, since the *order* of the checks is
observable behaviour. 21 tests in
`tests/Feature/Auth/AuthenticateAccountTest.php`.

### 2.1 Behaviour that matches

| Legacy behaviour | Status |
| --- | --- |
| Cleartext `login.user_pass` comparison when `UseMD5` is off | matches |
| Unsalted MD5 comparison when `UseMD5` is on | matches |
| Case-insensitive account names by default (`NoCase`) | matches |
| Binary comparison when the server is case-sensitive | matches |
| `sex = 'S'` inter-server accounts cannot sign in | matches |
| Accounts with a negative `group_id` cannot sign in | matches |
| IP ban reported before the credential check | matches |
| Expired `ipbanlist` rows ignored (`rtime > NOW()`) | matches |
| Octet-wildcard IP ban matching (`a.*.*.*` … `a.b.c.d`) | matches |
| Live temporary ban refuses | matches |
| Lapsed temporary ban is cleared and the sign-in proceeds | matches |
| Unconfirmed registration distinguished from permanent ban within `state = 5` | matches |
| Permanent ban refuses | matches |
| `AllowIpBanLogin` / `AllowTempBanLogin` / `AllowPermBanLogin` overrides | matches |
| Refusal reasons keep the legacy `Flux_LoginError` integer values | matches |

### 2.2 Behaviour that differs

| Difference | Why | Decision |
| --- | --- | --- |
| The panel verifies against its own bcrypt hash once one exists, and only falls back to rAthena's column before then. `login.user_pass` is never rewritten. | rAthena's login server reads that column directly and it is `varchar(32)`, so it can be neither re-hashed nor widened by the panel. | **D1** |
| Passwords are no longer written to `cp_loginlog` and the three other audit tables. | A stock FluxCP persisted every submitted password, including failed attempts, in cleartext. | **D2** |
| Comparisons use `hash_equals` rather than SQL equality. | A plain comparison short-circuits on the first differing byte. The legacy panel avoided this only by comparing inside SQL. | — |
| A non-existent account still costs one hash verification. | Otherwise "no such account" returns measurably faster than "wrong password" and the endpoint becomes an account-enumeration oracle. | — |
| Sign-in is rate limited per username and address. | The legacy panel had none, which with cleartext credentials made online guessing cheap. | — |

### 2.3 Known limitation: IPv6

`ipbanlist.list` was widened to 39 characters for IPv6, but rAthena's matching
is octet-and-wildcard based and has no IPv6 form. An IPv6 client therefore
cannot be matched against the ban list, and `IpBanService::isBanned()` reports
`false` for one.

This is inherited, not introduced: the legacy `isIpBanned()` guarded on
`/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/` and returned `false` for
anything else. Reporting the same answer is the honest option, since inventing
a matching scheme the emulator does not share would make the panel and the game
server disagree about who is banned.

---

## 3. Authorisation

**How this was checked.** `config/access.php` was parsed programmatically and
compared against the action files actually present on disk, and the legacy
comparison expression in `Flux_Authorization` was reproduced as a data-driven
test. 19 tests in `tests/Unit/Enums/AccountLevelTest.php`.

### 3.1 The privilege comparison matches exactly

The legacy expression is not a plain `>=`:

```php
$accessLevel == AccountLevel::ANYONE || $accessLevel == $accountLevel ||
  ($accessLevel != AccountLevel::UNAUTH && $accessLevel <= $accountLevel)
```

Both the exact-match clause and the `UNAUTH` exclusion are load-bearing: they
are what makes `UNAUTH` mean "guests only" rather than "guests and above", so a
signed-in visitor is kept off the login and registration pages even though
`0 > -1`. The ported comparison reproduces all three clauses, and the integer
values of every level are unchanged so a customised `access.php` carries over.

### 3.2 Behaviour that differs

| Difference | Why | Decision |
| --- | --- | --- |
| A route with no permission entry is refused, and the refusal is logged as a configuration fault. | FluxCP's check returned `-1` for an unknown module/action while the dispatcher only blocked on a strict `false`, so ten shipped actions were served to anyone. | **D3** |
| An unknown ability name resolves to `NOONE` instead of falling through. | An ability is referenced by literal name, so a typo must fail closed. This is what makes the `allowedToDonate` defect impossible to reintroduce unnoticed. | **D3**, **D10** |

### 3.3 Two legacy defects fixed rather than reproduced

**Ranking permission keys did not match the shipped actions.**

```
access.php keys   : bowman, character, death, guild, homun, mvp, spearman, swordman, zeny
action files      : alchemist, blacksmith, character, death, guild, homunculus, mvp, zeny
keys with no file : bowman, homun, spearman, swordman
files with no key : alchemist, blacksmith, homunculus
```

The four dead keys are leftovers from job-specific ladders that no longer exist,
plus `homun` renamed to `homunculus` without updating the key. The three real
rankings were consequently unlisted and reachable only by way of the `-1`
fall-through described above. The dead keys are dropped and the three ladders
given explicit `ANYONE` entries, which is both the evident intent and the
behaviour players see today (**D10**).

**The donate button never rendered.** `themes/default/account/view.php:71`
gates it on `$auth->allowedToDonate`, which resolves to
`featureAllowed('Donate')`. No `Donate` key exists in the legacy `features`
map, so the check always returned `false`. A `Donate` ability is now defined
(**D10**).

### 3.4 Permissions that are now vestigial

Five abilities are pinned to `NOONE` in the legacy map specifically to hide
password columns in the UI: `SeeAccountPassword`, `SearchMD5Passwords`,
`SeeCpLoginLogPass`, `SeeCpResetPass`, `SeeCpChangePass` (plus the matching
`SearchCp*` variants). Since this port writes no passwords to those columns
(**D2**), there is nothing left for them to reveal. They are retained at `NOONE`
so that a panel pointed at a legacy database with historical rows still cannot
surface them.

---

## 4. War of Emperium scheduling

**How this was checked.** `Flux_Athena::isWoe()` and its config parser were read
and their failure modes reproduced as tests. 11 tests in
`tests/Unit/Support/WoeWindowTest.php`.

Three legacy defects are fixed rather than reproduced (**D13**):

| Legacy behaviour | Port |
| --- | --- |
| A window whose end day precedes its start day never matched, because `strtotime()` resolves a day name relative to today and produced an end earlier than the start. | Windows are weekly intervals in minutes-from-week-start; one that does not advance is understood to wrap through the end of the week. |
| The per-pair timezone had no effect, because the comparison was against a Unix timestamp, which is timezone-independent. | Containment is evaluated in the pair's own timezone. |
| A malformed window was silently discarded, disabling that window's access restrictions with no error. | A malformed window raises `InvalidArgumentException` at boot. |

**Observable difference.** A server whose WoE window crosses midnight, or whose
char/map pair runs in a different timezone from the web host, will now have the
restriction applied where previously it was not. Window boundaries are also now
half-open — start inclusive, end exclusive — rather than open at both ends, so a
check exactly at the start minute counts as in progress.

---

## 5. Multi-server behaviour

**How this was checked.** Connections were resolved at runtime and queried
against separate databases to confirm the logs database is genuinely addressed
independently.

| Legacy behaviour | Status |
| --- | --- |
| Several server groups, each with one login server | preserved (**D5**) |
| Several char/map pairs per login server | preserved |
| Separate login, char/map, logs and web databases per group | preserved |
| Per-pair character database override | preserved |
| Logs database reachable on a different host | preserved, and now enforced by using a named connection rather than interpolating a database name into SQL |
| Per-session server group selection (`preferred_server`) | the registry supports it; the HTTP layer that sets it from a request is not yet built — see the matrix |

---

## 6. Not yet compared

Everything else. The matrix is the authority, and at the time of writing most
of the 133 module actions are `NOT_STARTED`. In particular these carry known
compatibility risk and have had no comparison performed:

- **The item and monster databases.** Eleven legacy actions depend on
  `Flux_TemporaryTable` merging `item_db` + `item_db_re` + `item_db2` +
  `item_db2_re` with later tables overriding earlier rows (**D6**). A port that
  queries `item_db` directly looks correct on a vanilla install and silently
  serves pre-renewal stats while ignoring every custom item on a real server.
- **The PayPal donation flow**, including IPN verification and the credit hold
  and release behaviour.
- **The item shop**, its cart, and in-game delivery through `cp_redeemlog`.
- **`Flux_Paginator`'s sorting**, which took column names from the request. The
  replacement must validate them against an allow-list.
- **The 57 `Flux_Template` view helpers**, which encode a large amount of
  game-domain formatting (job names, equip slots, item flags, monster modes).
