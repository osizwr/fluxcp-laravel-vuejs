# Migration Audit

An honest account of what was discovered, what was built, and what was not.

The headline is simple: **the audit is complete, the foundation is complete, and
the feature migration is about 18% done**. 25 of FluxCP's 139 module actions are
finished — 16 ported and tested, 9 deliberately replaced — and 111 have not been
started. Nothing here is rounded up; the counts come from
[FLUXCP_MIGRATION_MATRIX.md](FLUXCP_MIGRATION_MATRIX.md), which is the
authority, and this file must not claim more than it shows.

---

## 1. What was discovered

Audited `rathena/FluxCP` at commit `98cd4b8` (2025-11-03), by reading the source
rather than the documentation.

| Question | Answer |
| --- | --- |
| How many legacy modules? | **32** |
| How many actions (`module/action` pairs)? | **139** |
| How many routes? | 139 — FluxCP has no router; an action *is* a route |
| How many controllers? | 0 in the usual sense. Each action is a bare PHP file executed in the template's scope |
| How many templates? | **151** across 3 themes (144 default, 4 bootstrap, 3 installer) |
| How many core library classes? | **36** under `lib/Flux/`, plus a bundled PHPMailer 5.x (49 files) |
| How many view helpers? | **57** public methods on `Flux_Template` |
| How many configuration options? | **361** in `config/application.php` alone |
| How many permissions? | 139 action keys + **46** named abilities |
| How many database interactions? | **100 of 139** actions issue SQL directly |
| How many plugins? | A loader plus **1** example add-on (`helloworld`) |
| How many languages? | 4 (en_us, es_es, id_id, pt_br) |
| Total PHP | **41,527 lines** |

### Database surface

| Connection | Tables the panel touches |
| --- | --- |
| login | 2 rAthena (`login`, `ipbanlist`) + 18 panel-owned |
| char/map | 34 rAthena + 7 panel-owned |
| logs | 10 rAthena log tables |
| web | rAthena's web database |

Plus 2 names that are not tables at all: `items` and `monsters` are MySQL
`TEMPORARY` tables built per request by merging `item_db` + `item_db_re` +
`item_db2` + `item_db2_re` with later tables overriding earlier rows. Eleven
actions depend on it.

Schemas were resolved by **executing** all 44 of FluxCP's versioned SQL files in
version order against a throwaway database, not by reading the deltas.

### Defects found in the legacy panel

Five, all verified against the source:

1. **Ten actions are reachable by anyone.** `actionAllowed()` returns `-1` for a
   module/action absent from `access.php`, and the dispatcher only blocks on a
   strict `false`. Affects three ranking ladders, both `buyingstore` actions,
   `woe/custom` and others.
2. **The `ranking` permission keys do not match the shipped actions.** Four keys
   (`bowman`, `homun`, `spearman`, `swordman`) have no action file; three action
   files (`alchemist`, `blacksmith`, `homunculus`) have no key.
3. **The donate button never renders.** It is gated on
   `$auth->allowedToDonate`, and no `Donate` ability exists in `access.php`, so
   the check always returns `false`.
4. **War of Emperium windows crossing midnight never match**, because
   `strtotime()` resolves a day name relative to today and produces an end
   earlier than the start. The per-pair timezone is also ignored, because the
   comparison is against a Unix timestamp.
5. **The peak player count is read from the wrong server.** The loop iterates
   `$athenaServer` but queries through `$server`, the session's *preferred*
   server, so with more than one char/map pair it reports another server's peak.

All five are fixed rather than reproduced, and recorded in
[MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) (D3, D10, D13) and
[COMPATIBILITY_REPORT.md](COMPATIBILITY_REPORT.md).

### The finding that shaped the architecture

`login.user_pass` is `varchar(32)` holding **cleartext**, or unsalted MD5 when
`UseMD5` is on. rAthena's own login server reads it directly, so the panel can
neither re-hash it (bcrypt is 60 characters and will not fit) nor widen it
(rAthena owns the schema). On the database inspected during the audit, stored
password lengths were 2, 4, 6 and 16 characters — cleartext.

Worse, FluxCP kept **its own copies** in four audit tables, including
`cp_loginlog` on every attempt, successful or not. A default install therefore
persists every password anyone ever types into the sign-in form.

The resolution is D1 and D2: the panel keeps a properly hashed credential of its
own and stops writing passwords to the audit tables, while leaving rAthena's
column exactly as the emulator needs it.

This was later confirmed against **rAthena's own source** rather than inferred
from FluxCP: `PASSWD_LENGTH (32 + 1)` in `src/common/mmo.hpp`, the comment
`char pass[32+1]; // 23+1 for plaintext, 32+1 for md5-ed passwords` in
`src/login/account.hpp`, and `login_check_password()` comparing with
`strcmp(sd.passwd, acc.pass)` — which under `passwordencrypt` MD5s the *stored
value itself*, so it has to remain reproducible by the client. See
COMPATIBILITY_REPORT.md section 1.5.

---

## 2. What was built

| | |
| --- | --- |
| PHP files | 107 |
| Configuration | 18 files (2,883 lines) |
| Vue components | 49 (11 core blocks, 8 fantasy blocks, pages, layouts, UI) |
| TypeScript modules | 20 |
| Migrations | 4 |
| Factories | 4 |
| Tests | 26 files, **349 tests, 1,247 assertions** |
| API endpoints | 21 |
| Documentation | 9 documents |

### Complete and tested

| Legacy action | Notes |
| --- | --- |
| `account/login` | All eight refusal reasons, in the legacy order. Credential handling per D1, audit per D2. Adds throttling, which the legacy panel had none of |
| `account/logout` | Session invalidated, token regenerated |
| `account/create` | Registration with CAPTCHA, the age gate, and optional e-mail confirmation. The account and its audit row are written in one transaction |
| `account/confirm` | Clears the hold, records the lift. Fixes the legacy bug that made confirmation impossible at the default setting |
| `account/resend` | Issues a new token rather than re-sending the old one, so a lapsed request can be renewed |
| `account/resetpass` | Both account name and address required; state, server-account and staff-level restrictions preserved; one answer in every case |
| `account/resetpw` | **Replaced** (D16): the link lets somebody choose a password instead of e-mailing one |
| `account/changepass` | Both credential stores updated together. Fixes the inverted staff policy (D17) and the backwards session handling (D18) |
| `account/changemail` | Honours `RequireChangeConfirm`; additionally requires the current password |
| `account/confirmemail` | Keyed on the signed-in account as well as the token, with the expiry the legacy lookup omitted |
| `account/prune` | **Replaced**: a scheduled command rather than a public HTTP endpoint, with three safety conditions the legacy `DELETE` lacked |
| `captcha/index` | Two drivers behind one contract (D19). Single use, expiring, digest-stored answer |
| `server/status` | Per-process reachability, live and peak players, WoE state. Measurement moved off the request path and broadcast over Reverb |
| `ranking/character` | Level ladder with every legacy exclusion filter |
| `ranking/zeny` | Plus the per-character opt-out |
| `character/online` | Paginated, searchable, permission-filtered, closed during WoE |

### Foundation complete

| Concern | |
| --- | --- |
| Server groups and runtime connections | Several groups, several char/map pairs each, four databases per group, per-pair database override |
| Authorisation | All 133 route permissions and 47 abilities ported; a gate per ability; deny by default |
| Authentication | rAthena credential compatibility, panel-side hash upgrade, custom user provider |
| Panel schema | All 25 `cp_*` tables, non-destructive, verified against the legacy end state |
| Realtime | Reverb with a polling fallback, verified end to end with a WebSocket client |
| Scheduling | Status measurement moved out of the request path |
| Design system | Tokens, light/dark, accessible components |
| Theme system | Swappable skins via `APP_THEME`, in three layers: design tokens, block overrides, and declarative page composition. Two themes ship. |
| Block system | 11 core blocks, overridable per theme with fallback to core. `fantasy` overrides 8 and inherits 3. |
| Page composition | A theme declares a page as an ordered list of blocks in `theme.json`; reordering a page is configuration, not code. |
| Site data | Statistics, class distribution and news endpoints, so the front-page blocks have real data rather than decoration. |
| Account credentials | Registration, e-mail confirmation, password reset, password change and e-mail change, with nine legacy defects fixed rather than carried over (D15–D19). |
| Mail | Four account mailables with HTML and text parts, sent through one service that decides the link format, the queueing and the failure behaviour. |
| Localisation | `lang/en` for the authentication and account messages. Before this, every `trans()` call in the sign-in path returned its own key to the visitor. |
| Game branding | `config/game.php`, consumed through `useGame()`; no component hardcodes the game name |

### Deliberately not ported

| Legacy | Why |
| --- | --- |
| `forum/index` | Action file and template are both **zero bytes** |
| `auction/index`, `economy/index` | Guard stub plus a bare `<h2>` heading. No behaviour exists to port |
| `errors/missing_action`, `errors/missing_view` | Debug-only pages, replaced by framework error handling |
| `install/index`, `install/reinstall` | File-ledger installer replaced by `panel:install-schema` (D4) |
| `Flux_Config`, `Flux_DataObject`, `Flux_LogFile` | Framework equivalents |
| Bundled PHPMailer 5.x | Laravel Mail |
| `lib/functions/getReposVersion` | Phones home on page load |
| Add-on loader | Laravel packages (D8) |
| PHP theme inheritance | Design tokens and components (D7) |

---

## 3. What was not built

**111 of 139 actions.** The matrix lists each one; grouped by area:

| Area | Actions | Includes |
| --- | --: | --- |
| Game logs (`logdata`) | 13 | Pick, zeny, MVP, chat, command, branch, feeding, cash |
| Admin logs (`cplog`) | 10 | Every control-panel audit view |
| News and pages CMS | 9 | The admin half of news (add, edit, delete, manage) and all of the static-page CMS. Public news listing and article views are done |
| Character | 8 | Detail view, slot change, look and position reset, divorce, map statistics, preferences |
| Support desk | 8 | Player and staff ticket flows, categories, settings |
| Item shop (`purchase`) | 7 | Browse, cart, checkout, pending delivery |
| Account | 6 | Sex change, credit transfer and its log, the item-shop cart, and the admin search and edit screens |
| Donations | 6 | PayPal flow, IPN, history, trusted donors |
| History | 6 | Self-service account history |
| Rankings | 6 | Alchemist, blacksmith, death, guild, homunculus, MVP |
| IP bans | 5 | |
| Guild | 4 | Browse, detail, emblem rendering, CSV export |
| Item shop admin | 4 | |
| Vending / buying stores | 4 | |
| Item database | 3 | Depends on the temporary-table merge (D6) |
| Monster database | 2 | Same |
| Other | 10 | Castle ownership, the WoE schedule and its custom variant, the admin mail tool, web commands, ToS, `server/status-xml`, the `unauthorized` page, `main/page_not_found` and the global `preprocess` hook |

The rows sum to 111, which is the figure in
[FLUXCP_MIGRATION_MATRIX.md](FLUXCP_MIGRATION_MATRIX.md). Two rows were wrong in
the previous revision of this file — rankings was understated as 3 and "other"
overstated as 13 — and the table is now generated from the matrix rather than
maintained by hand.

### Cross-cutting work outstanding

- **The item/monster temporary-table merge (D6).** Eleven actions need it. A port
  that queries `item_db` directly looks correct on a vanilla install and silently
  serves pre-renewal stats while ignoring every custom item on a real server.
- **Pagination with sortable columns.** Needed by roughly fifteen listings and
  every admin page.

  A correction to an earlier revision of this document, which claimed
  `Flux_Paginator` took column names from the request and was therefore an
  injection surface. Re-reading it, that is wrong: `getSQL()` iterates
  `$this->sortableColumns`, an allow-list every calling module passes as a
  hardcoded array, and the request supplies only the sort *direction*, checked
  against `ASC`/`DESC`/`NONE`. The page number is interpolated into `LIMIT`
  only after arithmetic has coerced it to a number. No module was found
  passing request data to `setSortableColumns()`.

  So the replacement needs an allow-list because that is the right design, not
  because it closes a hole. The real reasons to do it early are that it is
  needed everywhere and that doing it afterwards means retrofitting every
  listing.
- **The 57 `Flux_Template` view helpers**, which encode a large amount of
  game-domain formatting: job names, equip slots, item flags, monster modes,
  trade restrictions. Two are ported (`jobs`, `homunculus`).
- **Localisation.** `lang/en` now covers the authentication and account
  messages, which fixed a real defect: there was no `lang/` directory at all, so
  every `trans()` call in the sign-in path returned its own key and a failed
  sign-in showed the visitor the literal string
  `auth.failure.invalid_credentials`. The other three upstream languages are not
  ported, and most interface copy is still inline in the Vue components.
- **Mail beyond the account flows.** `Flux_Mailer` shipped nine templates. Four
  are ported or replaced — `confirm`, `resetpass`, `changemail`, and `newpass`
  which became a change *notice* rather than a password (D16). The remaining
  four are not, because the features that send them are not built:
  `contactform`, `newticket` and `ticketreply` belong to the support desk, and
  `marketing1` to the admin broadcast tool (`mail/index`).

  Two deliberate differences from `Flux_Mailer`: no mailable is queued by
  default (see `PANEL_QUEUE_MAIL` and the reasoning in `config/panel.php`), and
  the success log line records the mailable class but **not** the recipient.
  The legacy log included the address, which over time builds a file mapping
  account activity to e-mail addresses, readable by anyone who can read the
  application's logs.
- **Database-backed admin-editable settings** (D12). Policy currently lives in
  `config/panel.php` and needs a deploy to change.
- **A worked add-on package** (D8). The permission registry is designed for
  third-party contribution, but no example exists.
- **Frontend tests.** The backend has 349; the Vue layer has none.

---

## 4. Verification

Every command below was run, and these are its real results.

| Command | Result |
| --- | --- |
| `composer test` | **349 passed**, 1,247 assertions, 0 failures, 18s |
| `composer lint` (Pint) | **passed** |
| `npm run lint` (ESLint + `vue-tsc`) | **passed**, 0 errors, 0 warnings |
| `npm run build` | **passed**, 16 chunks, 41.7 kB gzipped entry |
| `php artisan panel:install-schema` | **25 tables created**, re-run reports 25 present |
| `php artisan panel:broadcast-server-status` | **dispatched**, received by a WebSocket client |

### Checks beyond "it compiles"

- **Schema fidelity.** The installed schema was compared column by column
  against the legacy end state: 25 tables, 196 columns, **11 differences**, all
  benign type widenings, each recorded in the compatibility report.
- **Realtime end to end.** A WebSocket client connected to a running Reverb
  server, subscribed to `server-status`, and received the dispatched event with
  the expected payload. Not inferred from the absence of an error.
- **The credential guarantee.** A test asserts `login.user_pass` is byte-for-byte
  unchanged after a sign-in, and another asserts a sign-in still succeeds after
  rAthena's copy is scrambled — proving the panel verified against its own hash.
- **The audit guarantee.** A test asserts no password reaches `cp_loginlog`.
- **Integration, not simulation.** rAthena-facing tests run against a real
  MariaDB schema, exercising an 80-column reserved-word table, enum columns and
  cross-database connections.

### Defects the tests caught while being written

Worth recording, because each would have been a production fault:

1. `Account` did not implement `Authorizable`, so every `$user->can()` check in a
   resource or middleware was a **fatal error** rather than a denial.
2. Eloquent copies the parent's connection onto a related model that names none,
   so `panel_credentials` was queried against **rAthena's** database.
3. The status probe was `final` and therefore unmockable; tests were opening real
   sockets.
4. MariaDB reports a `CURRENT_TIMESTAMP` default as `current_timestamp()`, which
   the schema generator emitted as a string literal — four service desk tables
   would not create.
5. The schema generator **lowercased enum values**, so the installer created
   `cp_createlog.sex` as `enum('m','f','s')` with a default of `'m'`. MariaDB's
   case-insensitive collation hid it: inserting `'M'` silently stored `'m'`.
   Worse, **the schema comparison that was supposed to catch this normalised it
   away**, lowercasing both sides before comparing. The comparison is now
   case-sensitive and also checks defaults. A verification that normalises a
   difference cannot detect that difference, and this one normalised exactly
   the field it needed to check.
6. Sanctum's SPA mode attaches the session only when a request carries a `Referer`
   or `Origin` header, so a request without one silently lost its session.
7. `cp_pwchange` has columns `change_date` and `change_ip`, not the
   `request_date`/`ip` an initial implementation of the account service assumed.

### A data-loss incident in the test harness

Recorded because it is the most serious defect found in this project's own code,
and because the fix is a pattern worth keeping.

The harness took its table listing from
`getSchemaBuilder()->getTableListing()` with **no schema argument**. On
MySQL/MariaDB that returns every table the connected user can see, **across every
database, schema-qualified** — on the development machine, 555 names of which 458
belonged to unrelated databases. Truncating each name in turn therefore emptied
**every database on the server**, not just the test one.

It went unnoticed because the suite passed throughout: the test databases were
correctly configured and correctly reset, so the symptom was damage outside the
tests' own scope. The only visible hint was the suite taking 80 seconds; with the
listing scoped it takes 5, because it had been truncating 555 tables before
every single test.

Two changes, and the second is the one that matters:

1. The listing is scoped to the connection's own database, and a
   schema-qualified name reaching `truncate()` now throws.
2. **An allow-list of databases the harness may destroy.** A connection pointing
   anywhere else fails the test before any destructive call. Correct code is not
   enough on its own; a guard is what makes the next mistake loud instead of
   catastrophic.

Pinned by `tests/Feature/Diagnostic/TestIsolationTest.php`, which asserts the
scoping, asserts that the unscoped call really does differ on this server, and
asserts that the guard refuses a non-disposable database.

**The lesson generalises beyond tests.** Any destructive operation driven by a
listing should name its scope explicitly and verify the target before acting.
The deployment guide's advice to withhold `DROP` from the panel's database user
is the same principle applied to production.

---

## 5. Behaviour that differs from FluxCP

Summarised; each has its reasoning in
[MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) and its evidence in
[COMPATIBILITY_REPORT.md](COMPATIBILITY_REPORT.md).

| Difference | Decision |
| --- | --- |
| The panel verifies against its own hash; rAthena's column is never rewritten | D1 |
| Passwords are no longer written to four audit tables | D2 |
| A route with no permission entry is denied, not served | D3 |
| An unknown ability resolves to `NOONE` | D3 |
| Schema install is a command, not a per-request check | D4 |
| Queries name a connection instead of interpolating a database name | D5 |
| Theme inheritance replaced by design tokens and components | D7 |
| Add-ons become Laravel packages | D8 |
| Dead `ranking` permission keys dropped; `Donate` ability defined | D10 |
| Session auth in the `web` group; Sanctum removed | D11 |
| Configuration split by audience | D12 |
| WoE windows evaluated correctly and in the server's timezone | D13 |
| Realtime added; public channels carry only public data | D14 |
| Sign-in rate limited | — |
| `hash_equals` for credential comparison; constant-cost account lookup | — |
| Peak player count read from the correct database | — |

### Known limitation

**IPv6 addresses cannot be matched against `ipbanlist`.** rAthena's matching is
octet-and-wildcard based and has no IPv6 form, so an IPv6 client is reported as
not banned. This is inherited: the legacy `isIpBanned()` guarded on an IPv4
regex and returned `false` for anything else. Reporting the same answer keeps the
panel and the game server agreeing about who is banned.

---

## 6. Definition of done

Against the project's own checklist:

| | Item |
| --- | --- |
| ✅ | Entire FluxCP repository audited |
| ✅ | Every module, route and action inventoried |
| ✅ | Database dependencies documented and verified by execution |
| ✅ | Decisions recorded with evidence |
| ⬜ | Laravel backend implemented — **foundation yes, 124 actions no** |
| ⬜ | Vue frontend implemented — **6 pages, design system; most pages missing** |
| 🟨 | Authentication migrated — **sign-in and sign-out; not registration or reset** |
| ✅ | Authorization migrated — all 133 routes and 47 abilities |
| ⬜ | Admin functionality migrated — **none** |
| 🟨 | Player functionality migrated — **partial** |
| ✅ | Ragnarok database compatibility verified |
| 🟨 | Plugin functionality addressed — **decided (D8), not implemented** |
| 🟨 | Configuration migrated — **what the built features read** |
| ✅ | Realtime architecture implemented and verified |
| ✅ | Reverb configured |
| ✅ | Queues configured |
| 🟨 | API implemented — **21 endpoints** |
| 🟨 | Security review — **applied to what exists; no review of unbuilt code** |
| ✅ | Automated tests created — 349 |
| 🟨 | Legacy/new compatibility testing — **for what is built** |
| ✅ | Production build succeeds |
| ✅ | No placeholder functionality |
| ✅ | No debug code |
| ✅ | No AI references |
| ✅ | Third-party licences preserved |
| 🟨 | Documentation — **complete for what exists** |
| ✅ | Final audit completed |

**This is not a 100% migration and is not presented as one.** The audit,
architecture and verification method are finished; the feature work is 18% done.

### Suggested order for the remaining work

1. **The item/monster merge service (D6).** Thirteen actions depend on it, and
   until it exists none of them can be written correctly: a port that queries
   `item_db` directly looks right on a vanilla install and silently serves
   pre-renewal stats while ignoring every custom item on a real server.
2. **Validated sortable pagination.** Needed by every listing and admin page.
   Doing it before the listings means not retrofitting fifteen of them.
3. **Character detail and management**, which is the largest remaining
   player-facing area now that the credential flows are done.
4. **The remaining `Flux_Template` helpers**, as the pages that need them arrive.
   55 of 57 are still unported, and they encode most of the game-domain
   formatting the item, monster and character pages need.
5. **Guilds**, including emblem conversion.
6. **Admin**: bans, IP bans, account search and edit, the log browsers.
7. **Item shop and donations**, together — the credit flow spans both.
8. **Support desk.**
9. **Frontend tests.** Worth starting before the Vue surface grows further; the
   account pages added for the credential flows are the first substantial
   interactive forms and are currently covered only through the API.
10. **Database-backed admin settings (D12)**, once the policy surface has stopped
    moving.
11. **Localisation**, last, once the string surface has stopped moving. Most
    interface copy is still inline in the components.

Registration and password reset headed this list in the previous revision and
are now done, together with confirmation, resend, password change, e-mail change
and pruning. Mail was the unblocking step named there; it exists for the account
flows, and the admin broadcast tool is the only mail-dependent feature left.
