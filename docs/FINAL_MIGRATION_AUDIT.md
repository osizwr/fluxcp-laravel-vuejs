# Migration Audit

An honest account of what was discovered, what was built, and what was not.

The headline is simple: **the audit is complete, the foundation is complete, and
the feature migration is about 4% done**. 6 of FluxCP's 139 module actions are
finished and tested. Nothing here is rounded up.

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

---

## 2. What was built

| | |
| --- | --- |
| PHP files | 57 (5,602 lines) |
| Configuration | 16 files (2,517 lines) |
| Vue components | 17 |
| TypeScript modules | 9 |
| Frontend | 2,346 lines |
| Migrations | 4 |
| Factories | 4 |
| Tests | 11 files, **97 tests, 213 assertions** |
| Documentation | 7 documents (2,442 lines) |

### Complete and tested

| Legacy action | Notes |
| --- | --- |
| `account/login` | All eight refusal reasons, in the legacy order. Credential handling per D1, audit per D2. Adds throttling, which the legacy panel had none of |
| `account/logout` | Session invalidated, token regenerated |
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

**124 of 139 actions.** The matrix lists each one; grouped by area:

| Area | Actions | Includes |
| --- | --: | --- |
| Account | 16 | Registration, password reset and change, e-mail change and confirmation, sex change, credit transfer, admin search and edit |
| Admin logs (`cplog`) | 10 | Every control-panel audit view |
| Game logs (`logdata`) | 13 | Pick, zeny, MVP, chat, command, branch, feeding, cash |
| Support desk | 8 | Player and staff ticket flows, categories, settings |
| Item shop (`purchase`) | 7 | Browse, cart, checkout, pending delivery |
| Donations | 6 | PayPal flow, IPN, history, trusted donors |
| Character | 8 | Detail view, slot change, look and position reset, divorce, map statistics, preferences |
| History | 6 | Self-service account history |
| News and pages CMS | 11 | Public and admin |
| IP bans | 5 | |
| Item database | 3 | Depends on the temporary-table merge (D6) |
| Monster database | 2 | Same |
| Guild | 4 | Browse, detail, emblem rendering, CSV export |
| Item shop admin | 4 | |
| Rankings | 3 | Alchemist, blacksmith, homunculus |
| Vending / buying stores | 4 | |
| Other | 14 | Castles, WoE schedule, mail, web commands, CAPTCHA, ToS, server info, the global preprocess hook |

### Cross-cutting work outstanding

- **The item/monster temporary-table merge (D6).** Eleven actions need it. A port
  that queries `item_db` directly looks correct on a vanilla install and silently
  serves pre-renewal stats while ignoring every custom item on a real server.
- **Pagination with sortable columns.** `Flux_Paginator` took column names from
  the request. The replacement must validate them against an allow-list; the
  legacy behaviour is an injection surface.
- **The 57 `Flux_Template` view helpers**, which encode a large amount of
  game-domain formatting: job names, equip slots, item flags, monster modes,
  trade restrictions. Two are ported (`jobs`, `homunculus`).
- **Localisation.** 4 languages exist upstream; none are ported.
- **Mail.** No mailable is written, so nothing that depends on e-mail —
  registration confirmation, password reset, e-mail change — can work.
- **Database-backed admin-editable settings** (D12). Policy currently lives in
  `config/panel.php` and needs a deploy to change.
- **A worked add-on package** (D8). The permission registry is designed for
  third-party contribution, but no example exists.
- **Frontend tests.** The backend has 97; the Vue layer has none.

---

## 4. Verification

Every command below was run, and these are its real results.

| Command | Result |
| --- | --- |
| `composer test` | **97 passed**, 213 assertions, 0 failures, 80s |
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
5. Sanctum's SPA mode attaches the session only when a request carries a `Referer`
   or `Origin` header, so a request without one silently lost its session.

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
| 🟨 | API implemented — **8 endpoints** |
| 🟨 | Security review — **applied to what exists; no review of unbuilt code** |
| ✅ | Automated tests created — 97 |
| 🟨 | Legacy/new compatibility testing — **for what is built** |
| ✅ | Production build succeeds |
| ✅ | No placeholder functionality |
| ✅ | No debug code |
| ✅ | No AI references |
| ✅ | Third-party licences preserved |
| 🟨 | Documentation — **complete for what exists** |
| ✅ | Final audit completed |

**This is not a 100% migration and is not presented as one.** The audit,
architecture and verification method are finished; the feature work is 4% done.

### Suggested order for the remaining work

1. **Registration and password reset.** Needs mail, which several other features
   also wait on. The highest-value unblocking step.
2. **The item/monster merge service (D6).** Thirteen actions depend on it.
3. **Validated sortable pagination.** Needed by every listing and admin page, and
   closes an injection surface rather than reproducing it.
4. **Account management**: e-mail change, password change, preferences.
5. **Character detail and management.**
6. **Guilds**, including emblem conversion.
7. **The remaining `Flux_Template` helpers**, as the pages that need them arrive.
8. **Admin**: bans, IP bans, account search and edit, the log browsers.
9. **Item shop and donations**, together — the credit flow spans both.
10. **Support desk.**
11. **Localisation**, once the string surface has stopped moving.
