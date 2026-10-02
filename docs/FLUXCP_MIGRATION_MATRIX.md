# FluxCP Migration Matrix

Living status of every legacy component. This file is the authority on what is done; the
README and the final audit must not claim more than it shows.

## Status vocabulary

| Status | Meaning |
| --- | --- |
| `NOT_STARTED` | Inventoried, nothing built. |
| `ANALYZING` | Legacy behaviour being read in detail. |
| `IMPLEMENTING` | Code being written; not yet behaviour-checked. |
| `TESTING` | Implemented, automated tests being written or failing. |
| `VERIFIED` | Implemented **and** checked against legacy behaviour with a passing test. |
| `BLOCKED` | Cannot proceed; reason recorded. |
| `INTENTIONALLY_REPLACED` | Deliberately not a like-for-like port; decision recorded in [MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md). |

**`VERIFIED` requires a test.** Compiling, rendering or "looks right" does not qualify.

## Current totals

Module actions only (the 139 `module/action` pairs); libraries and cross-cutting
concerns are tracked in their own tables below.

| Status | Actions | Share |
| --- | --: | --: |
| `INTENTIONALLY_REPLACED` | 7 | 5% |
| `NOT_STARTED` | 132 | 95% |
| **Total** | **139** | |

## Module actions

### `account`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `cart` | `modules/account/cart.php` | `NORMAL` | `NOT_STARTED` |  |
| `changemail` | `modules/account/changemail.php` | `NORMAL` | `NOT_STARTED` |  |
| `changepass` | `modules/account/changepass.php` | `NORMAL` | `NOT_STARTED` |  |
| `changesex` | `modules/account/changesex.php` | `NORMAL` | `NOT_STARTED` |  |
| `confirm` | `modules/account/confirm.php` | `UNAUTH` | `NOT_STARTED` |  |
| `confirmemail` | `modules/account/confirmemail.php` | `NORMAL` | `NOT_STARTED` |  |
| `create` | `modules/account/create.php` | `UNAUTH` | `NOT_STARTED` |  |
| `edit` | `modules/account/edit.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/account/index.php` | `LOWGM` | `NOT_STARTED` |  |
| `login` | `modules/account/login.php` | `UNAUTH` | `NOT_STARTED` |  |
| `logout` | `modules/account/logout.php` | `NORMAL` | `NOT_STARTED` |  |
| `prune` | `modules/account/prune.php` | `ANYONE` | `NOT_STARTED` |  |
| `resend` | `modules/account/resend.php` | `UNAUTH` | `NOT_STARTED` |  |
| `resetpass` | `modules/account/resetpass.php` | `UNAUTH` | `NOT_STARTED` |  |
| `resetpw` | `modules/account/resetpw.php` | `UNAUTH` | `NOT_STARTED` |  |
| `transfer` | `modules/account/transfer.php` | `NORMAL` | `NOT_STARTED` |  |
| `view` | `modules/account/view.php` | `NORMAL` | `NOT_STARTED` |  |
| `xferlog` | `modules/account/xferlog.php` | `NORMAL` | `NOT_STARTED` |  |

### `auction`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/auction/index.php` | `LOWGM` | `INTENTIONALLY_REPLACED` | Legacy is a guard stub + `<h2>Auction</h2>`. No behaviour to port; see D10. |

### `buyingstore`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/buyingstore/index.php` | `UNLISTED` | `NOT_STARTED` |  |
| `viewshop` | `modules/buyingstore/viewshop.php` | `UNLISTED` | `NOT_STARTED` |  |

### `captcha`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/captcha/index.php` | `ANYONE` | `NOT_STARTED` | GD CAPTCHA image endpoint. Port keeps a server-rendered challenge plus the existing reCAPTCHA option. |

### `castle`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/castle/index.php` | `ANYONE` | `NOT_STARTED` |  |

### `character`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `changeslot` | `modules/character/changeslot.php` | `NORMAL` | `NOT_STARTED` |  |
| `divorce` | `modules/character/divorce.php` | `NORMAL` | `NOT_STARTED` |  |
| `index` | `modules/character/index.php` | `LOWGM` | `NOT_STARTED` |  |
| `mapstats` | `modules/character/mapstats.php` | `ANYONE` | `NOT_STARTED` |  |
| `online` | `modules/character/online.php` | `ANYONE` | `NOT_STARTED` |  |
| `prefs` | `modules/character/prefs.php` | `NORMAL` | `NOT_STARTED` |  |
| `resetlook` | `modules/character/resetlook.php` | `NORMAL` | `NOT_STARTED` |  |
| `resetpos` | `modules/character/resetpos.php` | `NORMAL` | `NOT_STARTED` |  |
| `view` | `modules/character/view.php` | `NORMAL` | `NOT_STARTED` |  |

### `cplog`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `ban` | `modules/cplog/ban.php` | `ADMIN` | `NOT_STARTED` |  |
| `changemail` | `modules/cplog/changemail.php` | `ADMIN` | `NOT_STARTED` |  |
| `changepass` | `modules/cplog/changepass.php` | `ADMIN` | `NOT_STARTED` |  |
| `create` | `modules/cplog/create.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/cplog/index.php` | `ADMIN` | `NOT_STARTED` |  |
| `ipban` | `modules/cplog/ipban.php` | `ADMIN` | `NOT_STARTED` |  |
| `login` | `modules/cplog/login.php` | `ADMIN` | `NOT_STARTED` |  |
| `paypal` | `modules/cplog/paypal.php` | `ADMIN` | `NOT_STARTED` |  |
| `resetpass` | `modules/cplog/resetpass.php` | `ADMIN` | `NOT_STARTED` |  |
| `txnview` | `modules/cplog/txnview.php` | `ADMIN` | `NOT_STARTED` |  |

### `donate`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `complete` | `modules/donate/complete.php` | `ANYONE` | `NOT_STARTED` |  |
| `history` | `modules/donate/history.php` | `NORMAL` | `NOT_STARTED` |  |
| `index` | `modules/donate/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `notify` | `modules/donate/notify.php` | `ANYONE` | `NOT_STARTED` |  |
| `trusted` | `modules/donate/trusted.php` | `NORMAL` | `NOT_STARTED` |  |
| `update` | `modules/donate/update.php` | `ANYONE` | `NOT_STARTED` |  |

### `economy`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/economy/index.php` | `NORMAL` | `INTENTIONALLY_REPLACED` | Legacy is a guard stub + `<h2>Economy</h2>`. No behaviour to port. |

### `errors`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `missing_action` | `modules/errors/missing_action.php` | `UNLISTED` | `INTENTIONALLY_REPLACED` | Debug-only page for unresolved actions. Replaced by framework error handling. |
| `missing_view` | `modules/errors/missing_view.php` | `UNLISTED` | `INTENTIONALLY_REPLACED` | Debug-only page for unresolved views. Replaced by framework error handling. |

### `forum`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/forum/index.php` | `UNLISTED` | `INTENTIONALLY_REPLACED` | Legacy action file and template are both zero bytes. Dropped; see D10. |

### `guild`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `emblem` | `modules/guild/emblem.php` | `ANYONE` | `NOT_STARTED` |  |
| `export` | `modules/guild/export.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/guild/index.php` | `LOWGM` | `NOT_STARTED` |  |
| `view` | `modules/guild/view.php` | `NORMAL` | `NOT_STARTED` |  |

### `history`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `cplogin` | `modules/history/cplogin.php` | `NORMAL` | `NOT_STARTED` |  |
| `emailchange` | `modules/history/emailchange.php` | `NORMAL` | `NOT_STARTED` |  |
| `gamelogin` | `modules/history/gamelogin.php` | `NORMAL` | `NOT_STARTED` |  |
| `index` | `modules/history/index.php` | `NORMAL` | `NOT_STARTED` |  |
| `passchange` | `modules/history/passchange.php` | `NORMAL` | `NOT_STARTED` |  |
| `passreset` | `modules/history/passreset.php` | `NORMAL` | `NOT_STARTED` |  |

### `install`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/install/index.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | File-ledger schema installer replaced by Laravel migrations; see D4. |
| `reinstall` | `modules/install/reinstall.php` | `ADMIN` | `INTENTIONALLY_REPLACED` | As above; see D4. |

### `ipban`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/ipban/add.php` | `ADMIN` | `NOT_STARTED` |  |
| `edit` | `modules/ipban/edit.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/ipban/index.php` | `ADMIN` | `NOT_STARTED` |  |
| `remove` | `modules/ipban/remove.php` | `ADMIN` | `NOT_STARTED` |  |
| `unban` | `modules/ipban/unban.php` | `ADMIN` | `NOT_STARTED` |  |

### `item`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/item/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `iteminfo` | `modules/item/iteminfo.php` | `ADMIN` | `NOT_STARTED` |  |
| `view` | `modules/item/view.php` | `ANYONE` | `NOT_STARTED` |  |

### `itemshop`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/itemshop/add.php` | `ADMIN` | `NOT_STARTED` |  |
| `delete` | `modules/itemshop/delete.php` | `ADMIN` | `NOT_STARTED` |  |
| `edit` | `modules/itemshop/edit.php` | `ADMIN` | `NOT_STARTED` |  |
| `imagedel` | `modules/itemshop/imagedel.php` | `ADMIN` | `NOT_STARTED` |  |

### `logdata`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `branch` | `modules/logdata/branch.php` | `ADMIN` | `NOT_STARTED` |  |
| `cashpoints` | `modules/logdata/cashpoints.php` | `ADMIN` | `NOT_STARTED` |  |
| `char` | `modules/logdata/char.php` | `ADMIN` | `NOT_STARTED` |  |
| `chat` | `modules/logdata/chat.php` | `ADMIN` | `NOT_STARTED` |  |
| `command` | `modules/logdata/command.php` | `ADMIN` | `NOT_STARTED` |  |
| `feeding` | `modules/logdata/feeding.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/logdata/index.php` | `ADMIN` | `NOT_STARTED` |  |
| `inter` | `modules/logdata/inter.php` | `ADMIN` | `NOT_STARTED` |  |
| `login` | `modules/logdata/login.php` | `ADMIN` | `NOT_STARTED` |  |
| `mvp` | `modules/logdata/mvp.php` | `ADMIN` | `NOT_STARTED` |  |
| `npc` | `modules/logdata/npc.php` | `ADMIN` | `NOT_STARTED` |  |
| `pick` | `modules/logdata/pick.php` | `ADMIN` | `NOT_STARTED` |  |
| `zeny` | `modules/logdata/zeny.php` | `ADMIN` | `NOT_STARTED` |  |

### `mail`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/mail/index.php` | `ADMIN` | `NOT_STARTED` |  |

### `main`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/main/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `page_not_found` | `modules/main/page_not_found.php` | `ANYONE` | `NOT_STARTED` |  |
| `preprocess` | `modules/main/preprocess.php` | `ANYONE` | `NOT_STARTED` | Global pre-dispatch hook: date-field assembly, installer redirect, credit unhold, account prune, PayPal return, server/theme switch, WoE gate. Decomposes into middleware + scheduled jobs. |

### `monster`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/monster/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `view` | `modules/monster/view.php` | `ANYONE` | `NOT_STARTED` |  |

### `news`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/news/add.php` | `ADMIN` | `NOT_STARTED` |  |
| `delete` | `modules/news/delete.php` | `ADMIN` | `NOT_STARTED` |  |
| `edit` | `modules/news/edit.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/news/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `manage` | `modules/news/manage.php` | `ADMIN` | `NOT_STARTED` |  |
| `view` | `modules/news/view.php` | `ANYONE` | `NOT_STARTED` |  |

### `pages`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/pages/add.php` | `ADMIN` | `NOT_STARTED` |  |
| `content` | `modules/pages/content.php` | `ANYONE` | `NOT_STARTED` |  |
| `delete` | `modules/pages/delete.php` | `ADMIN` | `NOT_STARTED` |  |
| `edit` | `modules/pages/edit.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/pages/index.php` | `ADMIN` | `NOT_STARTED` |  |

### `purchase`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/purchase/add.php` | `ANYONE` | `NOT_STARTED` |  |
| `cart` | `modules/purchase/cart.php` | `NORMAL` | `NOT_STARTED` |  |
| `checkout` | `modules/purchase/checkout.php` | `NORMAL` | `NOT_STARTED` |  |
| `clear` | `modules/purchase/clear.php` | `NORMAL` | `NOT_STARTED` |  |
| `index` | `modules/purchase/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `pending` | `modules/purchase/pending.php` | `NORMAL` | `NOT_STARTED` |  |
| `remove` | `modules/purchase/remove.php` | `NORMAL` | `NOT_STARTED` |  |

### `ranking`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `alchemist` | `modules/ranking/alchemist.php` | `UNLISTED` | `NOT_STARTED` |  |
| `blacksmith` | `modules/ranking/blacksmith.php` | `UNLISTED` | `NOT_STARTED` |  |
| `character` | `modules/ranking/character.php` | `ANYONE` | `NOT_STARTED` |  |
| `death` | `modules/ranking/death.php` | `ANYONE` | `NOT_STARTED` |  |
| `guild` | `modules/ranking/guild.php` | `ANYONE` | `NOT_STARTED` |  |
| `homunculus` | `modules/ranking/homunculus.php` | `UNLISTED` | `NOT_STARTED` |  |
| `mvp` | `modules/ranking/mvp.php` | `ANYONE` | `NOT_STARTED` |  |
| `zeny` | `modules/ranking/zeny.php` | `ANYONE` | `NOT_STARTED` |  |

### `server`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `info` | `modules/server/info.php` | `ANYONE` | `NOT_STARTED` |  |
| `status` | `modules/server/status.php` | `ANYONE` | `NOT_STARTED` |  |
| `status-xml` | `modules/server/status-xml.php` | `ANYONE` | `NOT_STARTED` |  |

### `service`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `tos` | `modules/service/tos.php` | `ANYONE` | `NOT_STARTED` |  |

### `servicedesk`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `catcontrol` | `modules/servicedesk/catcontrol.php` | `HIGHGM` | `NOT_STARTED` |  |
| `create` | `modules/servicedesk/create.php` | `NORMAL` | `NOT_STARTED` |  |
| `index` | `modules/servicedesk/index.php` | `NORMAL` | `NOT_STARTED` |  |
| `staffindex` | `modules/servicedesk/staffindex.php` | `LOWGM` | `NOT_STARTED` |  |
| `staffsettings` | `modules/servicedesk/staffsettings.php` | `LOWGM` | `NOT_STARTED` |  |
| `staffview` | `modules/servicedesk/staffview.php` | `LOWGM` | `NOT_STARTED` |  |
| `staffviewclosed` | `modules/servicedesk/staffviewclosed.php` | `LOWGM` | `NOT_STARTED` |  |
| `view` | `modules/servicedesk/view.php` | `NORMAL` | `NOT_STARTED` |  |

### `unauthorized`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/unauthorized/index.php` | `UNLISTED` | `NOT_STARTED` |  |

### `vending`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/vending/index.php` | `ANYONE` | `NOT_STARTED` |  |
| `viewshop` | `modules/vending/viewshop.php` | `ANYONE` | `NOT_STARTED` |  |

### `webcommands`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/webcommands/index.php` | `ADMIN` | `NOT_STARTED` |  |

### `woe`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `custom` | `modules/woe/custom.php` | `UNLISTED` | `NOT_STARTED` |  |
| `index` | `modules/woe/index.php` | `ANYONE` | `NOT_STARTED` |  |

## Core libraries (`legacy/lib/Flux/`)

| Legacy class | Responsibility | Replacement | Status |
| --- | --- | --- | --- |
| `Flux` | Static config/registry god-object + helpers | Laravel config + dedicated services | `NOT_STARTED` |
| `Flux_Config` | Dot-notation array wrapper | `Illuminate\Config` / typed value objects | `INTENTIONALLY_REPLACED` |
| `Flux_Connection` | Multi-database PDO wrapper (main/logs/web) | Runtime-registered Laravel connections (D5) | `NOT_STARTED` |
| `Flux_Connection_Statement` | PDO statement wrapper with encoding conversion | Query builder / PDO via Laravel | `INTENTIONALLY_REPLACED` |
| `Flux_Dispatcher` | module/action router + auth gate | Laravel router + permission middleware | `NOT_STARTED` |
| `Flux_Template` | View renderer **and** 57 view helpers | Blade shell + Vue components + API resources | `NOT_STARTED` |
| `Flux_Authorization` | Access-level checks, `allowedTo*` magic getters | Permission registry + Gates/Policies (D3) | `NOT_STARTED` |
| `Flux_SessionData` | Session state (account, server, theme, messages) | Laravel session + authenticated user | `NOT_STARTED` |
| `Flux_DataObject` | Array-to-object row wrapper | Eloquent models | `INTENTIONALLY_REPLACED` |
| `Flux_LoginServer` | Auth, registration, bans, credits, prefs | Split into auth, ban, credit and preference services | `NOT_STARTED` |
| `Flux_CharServer / Flux_MapServer / Flux_BaseServer` | TCP reachability probe via `fsockopen` | Server status service + cached probe | `NOT_STARTED` |
| `Flux_Athena / Flux_LoginAthenaGroup` | Server-group containers | Server-group registry (D5) | `NOT_STARTED` |
| `Flux_TemporaryTable` | Destructive item/mob table merge | Dedicated merge service (D6) | `NOT_STARTED` |
| `Flux_Paginator` | Sortable/filterable SQL pagination | Laravel pagination + validated sort allow-list | `NOT_STARTED` |
| `Flux_Installer*` | File-ledger schema installer (4 classes) | Laravel migrations (D4) | `INTENTIONALLY_REPLACED` |
| `Flux_Captcha` | GD CAPTCHA generator | CAPTCHA service, reCAPTCHA retained | `NOT_STARTED` |
| `Flux_EmblemExporter` | Guild emblem BMP/GIF conversion | Emblem service | `NOT_STARTED` |
| `Flux_ItemShop / Flux_ItemShop_Cart` | Credit shop + session cart | Shop + cart services | `NOT_STARTED` |
| `Flux_PaymentNotifyRequest` | PayPal IPN verification and crediting | Queued IPN job (D-pending) | `NOT_STARTED` |
| `Flux_Mailer` | PHPMailer wrapper | Laravel Mail + queued mailables | `NOT_STARTED` |
| `Flux_LogFile` | Plain-text file logger | Laravel logging channels | `INTENTIONALLY_REPLACED` |
| `Flux_Addon` | Add-on discovery and config merge | Laravel packages (D8) | `INTENTIONALLY_REPLACED` |
| `Flux_Error and 6 error subclasses` | Exception hierarchy | Typed exceptions + handler | `NOT_STARTED` |
| `lib/functions/discordwebhook` | Discord webhook notifications | Notification channel | `NOT_STARTED` |
| `lib/functions/getReposVersion` | Upstream version check | Dropped (phones home) | `INTENTIONALLY_REPLACED` |
| `lib/functions/imagecreatefrombmpstring` | BMP decoding for emblems | Emblem service helper | `NOT_STARTED` |
| `lib/phpmailer (49 files)` | Bundled PHPMailer 5.x | Laravel Mail (Symfony Mailer) | `INTENTIONALLY_REPLACED` |

## Cross-cutting concerns

| Concern | Legacy | Status | Notes |
| --- | --- | --- | --- |
| Routing | `Flux_Dispatcher` module/action | `NOT_STARTED` | API routes + SPA history routing. |
| Authentication | `Flux_LoginServer::isAuth()` | `NOT_STARTED` | Session auth via Sanctum SPA mode (D11); rAthena credential compatibility (D1). |
| Authorization | `access.php` 139 action keys + 46 features | `NOT_STARTED` | Permission registry, deny-by-default (D3). |
| Database connections | 4 handles per server group | `NOT_STARTED` | Runtime registration (D5). |
| Application schema | 25 `cp_*` tables, 44 SQL files | `NOT_STARTED` | Laravel migrations (D4). |
| Configuration | 361 options in one array | `NOT_STARTED` | Split by audience (D12). |
| Themes | PHP template inheritance, 3 themes | `NOT_STARTED` | Design tokens + components (D7). |
| Add-ons | `addons/` loader, 1 example | `NOT_STARTED` | Laravel packages (D8). |
| Localisation | 4 languages, `Flux::message()` | `NOT_STARTED` | Laravel translations + client catalogue. |
| Mail | Bundled PHPMailer 5.x | `NOT_STARTED` | Queued mailables. |
| Realtime | None (XML status feed, page refresh) | `NOT_STARTED` | Reverb broadcasting; new capability, not a port. |
| Queues/scheduling | Inline in `preprocess` on every request | `NOT_STARTED` | Jobs + scheduler. |
| Server status | `fsockopen` per request | `NOT_STARTED` | Probe service, cached, broadcast. |
| Pagination/sorting | `Flux_Paginator`, column names from request | `NOT_STARTED` | Validated sort allow-list (SQL-injection surface in legacy). |
| Automated tests | None in repository | `NOT_STARTED` | Pest/PHPUnit feature + integration tests. |

