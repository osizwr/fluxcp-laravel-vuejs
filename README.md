# rAthena Control Panel

A web control panel for [rAthena](https://github.com/rathena/rathena) Ragnarok
Online servers, rebuilt on Laravel 13 and Vue 3.

It is a reimplementation of [FluxCP](https://github.com/rathena/FluxCP), working
against the same rAthena database and the same `cp_*` tables, so it can be
pointed at an existing install without migrating data.

> ### Status: in progress, not production ready
>
> The migration is partial. Of FluxCP's 139 module actions, **6 are complete and
> covered by tests**, 2 are partially built, 7 are deliberately not being ported,
> and **124 have not been started**.
>
> [`docs/FLUXCP_MIGRATION_MATRIX.md`](docs/FLUXCP_MIGRATION_MATRIX.md) is the
> authority on what works. Nothing in this README claims more than it shows.
>
> What does work is complete rather than sketched: authentication reproduces
> FluxCP's sign-in behaviour step for step, and the schema installer has been
> compared column by column against the legacy schema.

---

## What works today

| Area | State |
| --- | --- |
| Sign in and out | Full legacy flow, all eight refusal reasons, rate limited |
| Account overview | Own account only |
| Own character list | Complete |
| Who's online | Paginated, searchable, permission-filtered, closed during WoE |
| Rankings | Level and zeny ladders with the legacy exclusion filters |
| Server status | Per-process reachability, live and peak players, realtime |
| Multi-server | Several server groups, several char/map pairs per group |
| Authorisation | All 133 route permissions and 47 abilities, deny by default |
| Schema install | All 25 `cp_*` tables, non-destructive |

Not built yet, among much else: registration, password reset, e-mail changes,
the item shop and its cart, donations, guild pages, the item and monster
databases, the news and page CMS, the support desk, the admin tools, and the
game log browsers.

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer, with `pdo_mysql` |
| Composer | 2.x |
| Node | 20 or newer |
| MySQL / MariaDB | MySQL 5.7+ or MariaDB 10.4+ |
| rAthena | A running server, or at least its database |

Optional: a Redis server for cache and queues, and
[Laravel Reverb](https://reverb.laravel.com) for realtime updates. Neither is
required — the panel falls back to database cache/queue and to polling.

## Installation

```bash
git clone <your-repository> panel
cd panel

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Then edit `.env`. Two blocks matter, and they are **different databases**:

- `DB_*` — the panel's own database, holding sessions, cache, the queue and the
  hashed credentials it keeps per account. Create an empty database for this.
- `RATHENA_*` — your existing Ragnarok database. The panel reads and writes game
  data here.

```bash
# The panel's own tables.
php artisan migrate

# The 25 cp_* tables, inside the rAthena database where FluxCP puts them.
php artisan panel:install-schema

npm run build
php artisan serve
```

`panel:install-schema` is additive: it skips any table that already exists and
never alters or drops one, so running it against a database that already carries
a FluxCP schema is safe. Use `--pretend` to see what it would do first.

### A note on the rAthena database

**Back it up before pointing anything at it.** This project does not create,
alter or drop rAthena's own tables, and does not seed them — but that is a
property of the code, not a guarantee about your environment.

Give the panel's database user the narrowest grants that work. It needs `SELECT`,
`INSERT`, `UPDATE` and `DELETE` on the game tables, plus `CREATE` on the database
once, for `panel:install-schema`. It does not need `DROP`.

## Ragnarok database configuration

FluxCP's `config/servers.php` becomes `config/rathena.php`. A **server group**
is one login server, the char/map pairs that share it, and up to four databases:

```
server group
├── login      the account table, plus 18 of the cp_* tables
├── char_map   the character tables, plus the other 7
├── logs       the game logs, commonly on a separate host
└── web        rAthena's own web database
```

One group driven by environment variables is configured out of the box, which
covers a single server. To front several, add entries to `groups` in
`config/rathena.php` with their own credentials.

Two settings must match your emulator, because getting them wrong does not
produce an error — it produces accounts that cannot sign in:

```env
RATHENA_LOGIN_USE_MD5=false          # rAthena's UseMD5
RATHENA_LOGIN_CASE_SENSITIVE=false   # the inverse of rAthena's NoCase
```

## Realtime (Reverb)

Optional. Without it the client polls and the panel works normally.

```bash
composer require laravel/reverb   # already installed
php artisan reverb:start
```

Set the credentials in `.env` — generate your own, and note that only the app
key reaches the browser:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Status is measured and broadcast by a scheduled command rather than on request:

```bash
php artisan panel:broadcast-server-status
```

Only data the status page already shows publicly goes on the public channel.
Anything account-scoped must use a private or presence channel, authorised in
`routes/channels.php`.

## Queues and the scheduler

The scheduler drives the status broadcast, so it needs to be running for
realtime updates:

```bash
php artisan schedule:work     # development
php artisan queue:work        # broadcasts are queued
```

In production, run the scheduler from cron and the worker under a supervisor.
See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Development

```bash
php artisan serve     # the application
npm run dev           # Vite with hot reload
php artisan queue:work
php artisan reverb:start
```

| Command | Does |
| --- | --- |
| `composer test` | PHPUnit |
| `composer lint` | Pint, check only |
| `composer format` | Pint, apply |
| `npm run lint` | ESLint and `vue-tsc` |
| `npm run typecheck` | `vue-tsc` only |
| `npm run build` | Production bundle |
| `npm run format` | Prettier |

## Testing

```bash
composer test
```

100 tests, 233 assertions.

The tests against rAthena's schema are **integration tests against a real
MySQL/MariaDB database**, not SQLite. They exercise an 80-column
reserved-word table, enum columns, cross-database connections and the
emulator's own id ranges; on SQLite they would prove the queries parse rather
than that they work.

The harness refuses to run against any database other than the two named in
`phpunit.xml`, and a schema-qualified table name reaching `truncate()` throws.
Both guards exist because an unscoped table listing once emptied every database
on a development machine — see the audit for the detail.

Create the two throwaway databases named in `phpunit.xml` before running them:

```sql
CREATE DATABASE fluxcp_test;
CREATE DATABASE fluxcp_test_logs;
```

The suite builds rAthena's tables itself, from `tests/Support/RathenaTestSchema.php`
— it does not need a checkout of the emulator, and does not redistribute
rAthena's SQL files. **Point it only at a disposable database.**

## Architecture

```
app/
├── Actions/          single-purpose operations (AuthenticateAccount)
├── Console/Commands/ panel:install-schema, panel:broadcast-server-status
├── Contracts/        interfaces for substitutable collaborators
├── Enums/            AccountLevel, AccountState, Gender, BanType, LoginFailure
├── Events/           broadcast events
├── Exceptions/
├── Http/
│   ├── Controllers/Api/
│   ├── Middleware/   permission, server group, WoE restriction
│   ├── Requests/
│   └── Resources/
├── Models/           rAthena tables + the panel's own
│   └── Concerns/     dynamic connection traits
├── Providers/
├── Services/         Auth, Server, Ranking, Rathena
└── Support/
    ├── Authorization/
    └── Rathena/      server groups, connections, WoE windows, schema

resources/js/
├── components/ui/    DataTable, StatusPill, StatTile, FormField, …
├── composables/
├── layouts/          the shell a theme replaces
├── pages/
├── router/
├── services/         api client, broadcasting
├── stores/           Pinia
└── types/
```

Three things are worth knowing before reading the code.

**Models resolve their connection at runtime.** A panel may front several server
groups and the active one is chosen per request, so `$connection` cannot be a
static property. `UsesLoginConnection`, `UsesCharMapConnection` and
`UsesLogsConnection` ask the registry instead.

**The permission map is the only place privilege is decided.**
`config/permissions.php` is generated from FluxCP's `access.php` and keeps its
`<module>.<action>` keys, so every entry traces back to the inventory. A route
with no entry is refused.

**`cp_*` tables stay inside rAthena's databases.** They are joined directly
against `login` and `char`, so relocating them would turn those joins into
cross-database joins that break the moment an operator splits the databases
across hosts.

## Security

Changes from the legacy panel, with the reasoning in
[`docs/MIGRATION_DECISIONS.md`](docs/MIGRATION_DECISIONS.md):

- **The panel keeps its own hashed credential** and verifies against it, falling
  back to rAthena's column only for accounts that have not signed in since the
  migration (D1).
- **Passwords are no longer written to the audit tables.** FluxCP recorded the
  submitted password in `cp_loginlog` on every attempt, successful or not, plus
  copies in three more tables (D2).
- **Unknown routes are denied.** FluxCP served ten shipped actions to anyone,
  because its check returned `-1` and its dispatcher only blocked on a strict
  `false` (D3).
- Session auth with CSRF, no token in JavaScript (D11).
- The session id is regenerated on sign-in; sign-in is rate limited.
- Credential comparisons use `hash_equals`, and a non-existent account still
  costs one hash verification so the endpoint is not an enumeration oracle.

### What this cannot fix

rAthena stores `login.user_pass` as cleartext, or unsalted MD5 when `UseMD5` is
on. The column is `varchar(32)` and the emulator's login server reads it
directly, so the panel can neither re-hash nor widen it. **A database compromise
exposes game passwords regardless of what the panel does.** That is a property
of the emulator's schema. Enabling `UseMD5` is weak but better than cleartext.

## Documentation

| | |
| --- | --- |
| [FLUXCP_FEATURE_INVENTORY.md](docs/FLUXCP_FEATURE_INVENTORY.md) | Every legacy action, its tables, permissions and view |
| [FLUXCP_MIGRATION_MATRIX.md](docs/FLUXCP_MIGRATION_MATRIX.md) | What is done — the authority |
| [DATABASE_ANALYSIS.md](docs/DATABASE_ANALYSIS.md) | Connections, schemas, the credential problem |
| [MIGRATION_DECISIONS.md](docs/MIGRATION_DECISIONS.md) | D1–D14, each with its evidence |
| [COMPATIBILITY_REPORT.md](docs/COMPATIBILITY_REPORT.md) | Differences established by comparison |
| [API.md](docs/API.md) | Endpoint reference |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment |
| [FINAL_MIGRATION_AUDIT.md](docs/FINAL_MIGRATION_AUDIT.md) | Counts and what remains |

## Licence and attribution

**GNU Lesser General Public License v3.0 or later**, matching upstream FluxCP.
See [LICENSE](LICENSE) and [NOTICE](NOTICE).

This project reproduces FluxCP's schema, permission model, configuration surface
and behaviour, and was written by reading its source, so it is a derivative work
in substance and carries the same licence. rAthena owns the game database schema
and is GPL-3.0; none of its source or SQL files is redistributed here.

The copyright holder for the code written for this project **has not been set**.
Before publishing, add your own copyright line to `NOTICE` and author metadata to
`composer.json`. Nothing has been filled in on your behalf, because a fabricated
copyright claim would make the attribution inaccurate.

The upstream FluxCP checkout under `legacy/` is kept as migration reference and
is excluded from version control.
