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
| `IMPLEMENTING` | 3 | 2% |
| `INTENTIONALLY_REPLACED` | 9 | 6% |
| `NOT_STARTED` | 111 | 80% |
| `VERIFIED` | 16 | 12% |
| **Total** | **139** | |

## Module actions

### `account`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `cart` | `modules/account/cart.php` | `NORMAL` | `NOT_STARTED` |  |
| `changemail` | `modules/account/changemail.php` | `NORMAL` | `VERIFIED` | `PUT /api/account/email` plus `/account/security`. Honours `RequireChangeConfirm`; with it on the address does not move until the new one is proven reachable. Requires the current password, which the legacy form did not ask for. 18 tests cover this and `confirmemail`. |
| `changepass` | `modules/account/changepass.php` | `NORMAL` | `VERIFIED` | `PUT /api/account/password` plus `/account/security`. Updates rAthena's column and the panel hash together, audited without passwords (D2). Fixes the inverted staff password policy (D17) and reverses the session handling: the account holder keeps their session and every other one ends (D18). 14 tests. |
| `changesex` | `modules/account/changesex.php` | `NORMAL` | `NOT_STARTED` |  |
| `confirm` | `modules/account/confirm.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/confirm` plus `/confirm-account`. Clears the hold and records the lift in `cp_banlog`. Fixes the legacy bug that made confirmation impossible at the default setting: `confirm_expire` was written only when `EmailConfirmExpire` was set, while the lookup always required `confirm_expire > NOW()`. 14 tests cover this and `resend`. |
| `confirmemail` | `modules/account/confirmemail.php` | `NORMAL` | `VERIFIED` | `POST /api/account/email/confirm` plus `/confirm-email`. Keyed on the signed-in account as well as the token, as the legacy action was. Adds the expiry the legacy lookup omitted, and re-checks that the address is still free. |
| `create` | `modules/account/create.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/register` plus `/register`. Credentials per D1, audit without the password per D2. CAPTCHA, the age gate, duplicate-name and duplicate-e-mail policy, and optional e-mail confirmation all present. Registration and the confirmation hold are one transaction, closing a legacy window in which a part-failed registration left a usable unconfirmed account. Signs the account in when no confirmation is required, as the legacy flow did. 21 tests. |
| `edit` | `modules/account/edit.php` | `ADMIN` | `NOT_STARTED` |  |
| `index` | `modules/account/index.php` | `LOWGM` | `NOT_STARTED` |  |
| `login` | `modules/account/login.php` | `UNAUTH` | `VERIFIED` | Full legacy flow including the order of its checks and all eight refusal reasons. Credentials handled per D1, audit per D2. Adds throttling. 33 tests. |
| `logout` | `modules/account/logout.php` | `NORMAL` | `VERIFIED` | Session invalidated and token regenerated. Tested. |
| `prune` | `modules/account/prune.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | `panel:prune-unconfirmed`, scheduled daily. Deleting accounts is no longer reachable over HTTP: the legacy action was a public endpoint guarded by comparing a query parameter against the installer password. Requires three conditions the legacy `DELETE` did not — still held, owns no characters, not staff — and has `--dry-run`. 12 tests. |
| `resend` | `modules/account/resend.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/confirm/resend` plus `/resend-confirmation`. Issues a new token rather than re-sending the old one, so a lapsed request can be renewed — the legacy action required an unexpired code and so refused the one case that needs it. Answers identically whether or not an account matched. |
| `resetpass` | `modules/account/resetpass.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/password/forgot` plus `/forgot-password`. Keeps the legacy restrictions: both account name and address required, state 0 only, no server accounts, and `NoResetPassGroupLevel`. Answers identically in every case, so the form cannot be used to find out which addresses are registered or which accounts are staff. 20 tests cover this and `resetpw`. |
| `resetpw` | `modules/account/resetpw.php` | `UNAUTH` | `INTENTIONALLY_REPLACED` | `POST /api/auth/password/reset` plus `/reset-password`. The legacy action generated a password and e-mailed it in cleartext; this one lets the account holder choose their own and sends no password at all (D16). Tokens are digests, expire, and are single use (D15) — the legacy flow never read `request_date`. |
| `transfer` | `modules/account/transfer.php` | `NORMAL` | `NOT_STARTED` |  |
| `view` | `modules/account/view.php` | `NORMAL` | `IMPLEMENTING` | **Own account only.** Viewing another account (the `ViewAccount` ability), the admin search of `account/index`, and the credit/ban panels are not built. |
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
| `index` | `modules/captcha/index.php` | `ANYONE` | `VERIFIED` | `GET /api/captcha`. Two drivers behind one contract (D19). The self-hosted one needs no font file, stores a digest of the answer rather than the answer, expires, and is consumed on a wrong answer as well as a right one — the legacy challenge was never cleared and could be replayed for the life of the session. 16 tests. |

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
| `online` | `modules/character/online.php` | `ANYONE` | `VERIFIED` | Paginated and searchable. Location withheld without `ViewOnlinePosition`, hidden characters withheld without `IgnoreHiddenPref`, listing refused during WoE. 7 tests. |
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
| `index` | `modules/main/index.php` | `ANYONE` | `IMPLEMENTING` | The client serves a front page showing server status. The legacy front page also rendered news, which depends on the news CMS. |
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
| `index` | `modules/news/index.php` | `ANYONE` | `VERIFIED` | Public listing from `cp_cmsnews`, newest first, paginated. Excerpts are derived with tags stripped; the stored rich text is returned only for a single article. No category or thumbnail, because the legacy schema has neither. 16 tests shared with the other site-data endpoints. |
| `manage` | `modules/news/manage.php` | `ADMIN` | `NOT_STARTED` |  |
| `view` | `modules/news/view.php` | `ANYONE` | `VERIFIED` | Single article including its body. |

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
| `alchemist` | `modules/ranking/alchemist.php` | `UNLISTED` | `NOT_STARTED` | Needs the alchemist job-class list and the fame column. |
| `blacksmith` | `modules/ranking/blacksmith.php` | `UNLISTED` | `NOT_STARTED` | Needs the blacksmith job-class list and the fame column. |
| `character` | `modules/ranking/character.php` | `ANYONE` | `VERIFIED` | Level ladder with the ban, staff, deletion and inactivity filters. Handles the login/char cross-database join the legacy panel assumed was always possible. 12 tests shared with the zeny ladder. |
| `death` | `modules/ranking/death.php` | `ANYONE` | `NOT_STARTED` |  |
| `guild` | `modules/ranking/guild.php` | `ANYONE` | `NOT_STARTED` |  |
| `homunculus` | `modules/ranking/homunculus.php` | `UNLISTED` | `NOT_STARTED` | Needs the homunculus table and class names, which are ported in config/rathena_reference.php. |
| `mvp` | `modules/ranking/mvp.php` | `ANYONE` | `NOT_STARTED` |  |
| `zeny` | `modules/ranking/zeny.php` | `ANYONE` | `VERIFIED` | As above, plus the per-character HideFromZenyRanking opt-out. |

### `server`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `info` | `modules/server/info.php` | `ANYONE` | `IMPLEMENTING` | `server.statistics` provides the aggregate counts the statistics block needs (accounts, characters, guilds, players online). The legacy page also listed rates and WoE times, which are configured rather than queried and are not yet surfaced. |
| `status` | `modules/server/status.php` | `ANYONE` | `VERIFIED` | Per-process reachability, live and peak player counts, WoE state. Measurement moved off the request path and broadcast over Reverb (D14). Peak now read from the correct database. 5 tests. |
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
| `Flux_Connection` | Multi-database PDO wrapper (main/logs/web) | Runtime-registered Laravel connections (D5) | `VERIFIED` |
| `Flux_Connection_Statement` | PDO statement wrapper with encoding conversion | Query builder / PDO via Laravel | `INTENTIONALLY_REPLACED` |
| `Flux_Dispatcher` | module/action router + auth gate | Laravel router + permission middleware | `VERIFIED` |
| `Flux_Template` | View renderer **and** 57 view helpers | Blade shell + Vue components + API resources | `NOT_STARTED` |
| `Flux_Authorization` | Access-level checks, `allowedTo*` magic getters | Permission registry + Gates/Policies (D3) | `VERIFIED` |
| `Flux_SessionData` | Session state (account, server, theme, messages) | Laravel session + authenticated user | `IMPLEMENTING` |
| `Flux_DataObject` | Array-to-object row wrapper | Eloquent models | `INTENTIONALLY_REPLACED` |
| `Flux_LoginServer` | Auth, registration, bans, credits, prefs | Split into auth, ban, credit and preference services | `IMPLEMENTING` |
| `Flux_CharServer / Flux_MapServer / Flux_BaseServer` | TCP reachability probe via `fsockopen` | Server status service + cached probe | `VERIFIED` |
| `Flux_Athena / Flux_LoginAthenaGroup` | Server-group containers | Server-group registry (D5) | `VERIFIED` |
| `Flux_TemporaryTable` | Destructive item/mob table merge | Dedicated merge service (D6) | `NOT_STARTED` |
| `Flux_Paginator` | Sortable/filterable SQL pagination | Laravel pagination + validated sort allow-list | `NOT_STARTED` |
| `Flux_Installer*` | File-ledger schema installer (4 classes) | `panel:install-schema` command (D4) | `VERIFIED` |
| `Flux_Captcha` | GD CAPTCHA generator | `ChallengesHumanity` with native and reCAPTCHA drivers | `VERIFIED` |
| `Flux_EmblemExporter` | Guild emblem BMP/GIF conversion | Emblem service | `NOT_STARTED` |
| `Flux_ItemShop / Flux_ItemShop_Cart` | Credit shop + session cart | Shop + cart services | `NOT_STARTED` |
| `Flux_PaymentNotifyRequest` | PayPal IPN verification and crediting | Queued IPN job (D-pending) | `NOT_STARTED` |
| `Flux_Mailer` | PHPMailer wrapper | Laravel Mail mailables via `AccountMailer` | `INTENTIONALLY_REPLACED` |
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
| Routing | `Flux_Dispatcher` module/action | `VERIFIED` | API routes + SPA history routing. 8 endpoints so far. |
| Authentication | `Flux_LoginServer::isAuth()` | `VERIFIED` | Session cookie auth in the web middleware group (D11); rAthena credential compatibility (D1). Registration and password reset not built. |
| Authorization | `access.php` 139 action keys + 46 features | `VERIFIED` | Permission registry with 133 route and 47 ability entries, deny-by-default (D3). Gates registered for every ability. |
| Database connections | 4 handles per server group | `VERIFIED` | Runtime registration (D5), plus co-location detection for the login/char join. |
| Application schema | 25 `cp_*` tables, 44 SQL files | `VERIFIED` | `panel:install-schema` (D4). 196 columns compared against the legacy end state; 11 benign type differences recorded in the compatibility report. |
| Configuration | 361 options in one array | `IMPLEMENTING` | Split by audience (D12). The options the built features read are ported; database-backed admin-editable settings are not built. |
| Themes | PHP template inheritance, 3 themes | `VERIFIED` | Design tokens plus file-resolution overrides for pages, layouts and components, selected by APP_THEME (D7). Two themes ship. Covered by 28 tests, including that the API is byte-identical whichever theme is active and that no theme file fetches data. |
| Add-ons | `addons/` loader, 1 example | `NOT_STARTED` | Laravel packages (D8). |
| Game branding | Hardcoded strings + SiteName config | `VERIFIED` | config/game.php consumed through useGame(). A test fails if any .vue file hardcodes the game name. |
| Localisation | 4 languages, `Flux::message()` | `IMPLEMENTING` | `lang/en` covers the authentication and account messages; before it, every `trans()` in the sign-in path returned its own key to the visitor. The other three upstream languages are not ported, and most interface copy is still inline in the Vue components. |
| Mail | Bundled PHPMailer 5.x | `IMPLEMENTING` | Four account mailables with HTML and text parts, sent through `AccountMailer`. Inline by default, queued behind `PANEL_QUEUE_MAIL`. The admin `mail/index` broadcast tool is not built. |
| Realtime | None (XML status feed, page refresh) | `VERIFIED` | Reverb broadcasting with a polling fallback (D14). Verified end to end with a WebSocket client. |
| Queues/scheduling | Inline in `preprocess` on every request | `IMPLEMENTING` | Status measurement and unconfirmed-account pruning are scheduled commands. Credit release is not built. |
| Server status | `fsockopen` per request | `VERIFIED` | Cached probe behind a contract, measured on a schedule and broadcast. |
| Pagination/sorting | `Flux_Paginator`, allow-listed columns, direction from request | `NOT_STARTED` | Laravel pagination plus a validated sort allow-list. Not a security fix: the legacy allow-list was always hardcoded by the calling module. |
| Automated tests | None in repository | `IMPLEMENTING` | 349 PHPUnit tests, 1,247 assertions, across 26 files. Integration tests run against a real MariaDB schema. No frontend tests exist. |

