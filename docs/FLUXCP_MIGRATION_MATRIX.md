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
| `INTENTIONALLY_REPLACED` | 20 | 14% |
| `VERIFIED` | 119 | 86% |
| **Total** | **139** | |

Every module action is now either ported with a passing test or deliberately
replaced with the reason recorded in
[MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md). That is **not** the same as
the migration being finished — see the cross-cutting table below and
[FINAL_MIGRATION_AUDIT.md](FINAL_MIGRATION_AUDIT.md) §3, which list what is
still outstanding outside the module actions.

**A row marked `VERIFIED` has been wrong before**, and `character/view` was
wrong twice — the first correction restored four of its ten data sets and
claimed the row finished. What finds these is not re-reading this table but two
scripted sweeps:

1. every table the legacy reads, against whether any code here touches it.
   `cp_itemdesc`, `friends`, `guild_expulsion`, `guild_storage` and `pet` came
   back with no readers.
2. every one of the legacy's 199 configuration options, against the new
   configuration and the decision log.

The first sweep has to match quoted identifiers, not substrings: an earlier
run missed `pet` because the word appears inside `competitive`, and missed
`inventory` because it appears in a comment. The second has to be triaged by
hand, because almost everything is renamed — but it is what turned up ten
missing game vocabularies, the staff exclusions, the ticket credit reward and
the RSS news source.

Both sweeps now come back empty. They are cheap to repeat and worth repeating
before this table is trusted again.

## Module actions

### `account`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `cart` | `modules/account/cart.php` | `NORMAL` | `INTENTIONALLY_REPLACED` | A menu link to the shop cart in the legacy, not an action of its own. `GET /api/shop/cart` serves it. |
| `changemail` | `modules/account/changemail.php` | `NORMAL` | `VERIFIED` | `PUT /api/account/email` plus `/account/security`. Honours `RequireChangeConfirm`; with it on the address does not move until the new one is proven reachable. Requires the current password, which the legacy form did not ask for. 18 tests cover this and `confirmemail`. |
| `changepass` | `modules/account/changepass.php` | `NORMAL` | `VERIFIED` | `PUT /api/account/password` plus `/account/security`. Updates rAthena's column and the panel hash together, audited without passwords (D2). Fixes the inverted staff password policy (D17) and reverses the session handling: the account holder keeps their session and every other one ends (D18). 14 tests. |
| `changesex` | `modules/account/changesex.php` | `NORMAL` | `VERIFIED` | Refuses while the account holds a gender-linked job — rAthena stores gender on the account, and a character left as a class its new gender cannot be is invisible or crashes the client. 17 tests cover this module. |
| `confirm` | `modules/account/confirm.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/confirm` plus `/confirm-account`. Clears the hold and records the lift in `cp_banlog`. Fixes the legacy bug that made confirmation impossible at the default setting: `confirm_expire` was written only when `EmailConfirmExpire` was set, while the lookup always required `confirm_expire > NOW()`. 14 tests cover this and `resend`. |
| `confirmemail` | `modules/account/confirmemail.php` | `NORMAL` | `VERIFIED` | `POST /api/account/email/confirm` plus `/confirm-email`. Keyed on the signed-in account as well as the token, as the legacy action was. Adds the expiry the legacy lookup omitted, and re-checks that the address is still free. |
| `create` | `modules/account/create.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/register` plus `/register`. Credentials per D1, audit without the password per D2. CAPTCHA, the age gate, duplicate-name and duplicate-e-mail policy, and optional e-mail confirmation all present. Registration and the confirmation hold are one transaction, closing a legacy window in which a part-failed registration left a usable unconfirmed account. Signs the account in when no confirmation is required, as the legacy flow did. 21 tests. |
| `edit` | `modules/account/edit.php` | `ADMIN` | `VERIFIED` | `PUT /api/admin/accounts/{id}`. Staff may not edit an account at or above their own rank, nor grant a rank they do not exceed, nor set a password. The audit columns the emulator writes are not editable. Balance changes need EditAccountBalance and are recorded in `cp_txnlog` with who made them. (D22) |
| `index` | `modules/account/index.php` | `LOWGM` | `VERIFIED` | `GET /api/admin/accounts`. Every legacy filter except the one that searched `login.user_pass` — on this schema that is cleartext or unsalted MD5, which makes it an oracle for finding every account sharing a password. See D22. 17 tests. |
| `login` | `modules/account/login.php` | `UNAUTH` | `VERIFIED` | Full legacy flow including the order of its checks and all eight refusal reasons. Credentials handled per D1, audit per D2. Adds throttling. 33 tests. |
| `logout` | `modules/account/logout.php` | `NORMAL` | `VERIFIED` | Session invalidated and token regenerated. Tested. |
| `prune` | `modules/account/prune.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | `panel:prune-unconfirmed`, scheduled daily. Deleting accounts is no longer reachable over HTTP: the legacy action was a public endpoint guarded by comparing a query parameter against the installer password. Requires three conditions the legacy `DELETE` did not — still held, owns no characters, not staff — and has `--dry-run`. 12 tests. |
| `resend` | `modules/account/resend.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/confirm/resend` plus `/resend-confirmation`. Issues a new token rather than re-sending the old one, so a lapsed request can be renewed — the legacy action required an unexpired code and so refused the one case that needs it. Answers identically whether or not an account matched. |
| `resetpass` | `modules/account/resetpass.php` | `UNAUTH` | `VERIFIED` | `POST /api/auth/password/forgot` plus `/forgot-password`. Keeps the legacy restrictions: both account name and address required, state 0 only, no server accounts, and `NoResetPassGroupLevel`. Answers identically in every case, so the form cannot be used to find out which addresses are registered or which accounts are staff. 20 tests cover this and `resetpw`. |
| `resetpw` | `modules/account/resetpw.php` | `UNAUTH` | `INTENTIONALLY_REPLACED` | `POST /api/auth/password/reset` plus `/reset-password`. The legacy action generated a password and e-mailed it in cleartext; this one lets the account holder choose their own and sends no password at all (D16). Tokens are digests, expire, and are single use (D15) — the legacy flow never read `request_date`. |
| `transfer` | `modules/account/transfer.php` | `NORMAL` | `VERIFIED` | Gains a cap and an off switch the legacy did not have, and refuses a transfer to your own account. (D23) |
| `view` | `modules/account/view.php` | `NORMAL` | `VERIFIED` | `GET /api/account` serves a player's own details and `GET /api/admin/accounts/{id}` the full staff view: credits, ban state, characters and recent ban history on one page. The staff view applies the same rank rule as editing — reading an account above your own is how a junior game master learns which address an administrator signs in from. |
| `xferlog` | `modules/account/xferlog.php` | `NORMAL` | `VERIFIED` | Readable from either end of the transfer. |

### `auction`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/auction/index.php` | `LOWGM` | `INTENTIONALLY_REPLACED` | Legacy is a guard stub + `<h2>Auction</h2>`. No behaviour to port; see D10. |

### `buyingstore`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/buyingstore/index.php` | `UNLISTED` | `VERIFIED` | Shares the vending implementation; the two differ only in which tables they read. |
| `viewshop` | `modules/buyingstore/viewshop.php` | `UNLISTED` | `VERIFIED` | A buying store holds no stock, so its items are on the line with no cart to join through. 16 tests cover shops and the world endpoints. |

### `captcha`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/captcha/index.php` | `ANYONE` | `VERIFIED` | `GET /api/captcha`. Two drivers behind one contract (D19). The self-hosted one needs no font file, stores a digest of the answer rather than the answer, expires, and is consumed on a wrong answer as well as a right one — the legacy challenge was never cleared and could be replayed for the life of the session. 16 tests. |

### `castle`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/castle/index.php` | `ANYONE` | `VERIFIED` | Castle ownership. An absent row and a `guild_id` of 0 both render as unowned, which is most castles on a young server. |

### `character`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `changeslot` | `modules/character/changeslot.php` | `NORMAL` | `VERIFIED` | Swaps with the occupant, in one transaction — the legacy used two statements, and half a swap leaves two characters in one slot. Refuses while either is online. |
| `divorce` | `modules/character/divorce.php` | `NORMAL` | `VERIFIED` | Clears both sides, and the child and rings per configuration. The rings are found by reconstructing rAthena's split of the partner id across two signed card columns. |
| `index` | `modules/character/index.php` | `LOWGM` | `VERIFIED` | `GET /api/admin/characters`. Staff search across accounts, with named comparison operators so nothing from a request reaches SQL as an operator. |
| `mapstats` | `modules/character/mapstats.php` | `ANYONE` | `VERIFIED` | Players per map. Characters who hid their map are left out entirely rather than counted, because a count of one locates them by elimination. 27 tests cover the character module. |
| `online` | `modules/character/online.php` | `ANYONE` | `VERIFIED` | Paginated and searchable. Location withheld without `ViewOnlinePosition`, hidden characters withheld without `IgnoreHiddenPref`, listing refused during WoE. 7 tests. |
| `prefs` | `modules/character/prefs.php` | `NORMAL` | `VERIFIED` | Read and write the three per-character preferences. Hiding from the zeny ladder needs its own ability and is dropped from the submission rather than refusing the rest. |
| `resetlook` | `modules/character/resetlook.php` | `NORMAL` | `VERIFIED` | Unequips everything and clears the appearance. `body` is set to the class, not zero, matching the legacy statement. |
| `resetpos` | `modules/character/resetpos.php` | `NORMAL` | `VERIFIED` | Returns the character to their save point. The operator's deny list is matched without the `.gat` extension, which rAthena writes inconsistently. |
| `view` | `modules/character/view.php` | `NORMAL` | `VERIFIED` | `GET /api/characters/{id}` plus `/characters/{id}`. Own character always; somebody else's needs ViewCharacter. The legacy assembled ten data sets here and two earlier revisions of this row claimed it done while returning the character alone: equipment and inventory, cart, friends, party roster, pet, homunculus with its stat block, partner/parents/child by name, the party's name and leader, the guild rank with its tax, and the death count rAthena keeps in `char_reg_num` rather than as a column. All ten are present. Unidentified items are staff-only, as the legacy had them. 22 tests cover this action alone. |

### `cplog`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `ban` | `modules/cplog/ban.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `changemail` | `modules/cplog/changemail.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `changepass` | `modules/cplog/changepass.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `create` | `modules/cplog/create.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `index` | `modules/cplog/index.php` | `ADMIN` | `INTENTIONALLY_REPLACED` | A menu with no data of its own; `GET /api/logs` lists the views the viewer may open, and the menu itself is a client route. |
| `ipban` | `modules/cplog/ipban.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `login` | `modules/cplog/login.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `paypal` | `modules/cplog/paypal.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `resetpass` | `modules/cplog/resetpass.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) `cp_resetpass.code` is deliberately not among its columns. |
| `txnview` | `modules/cplog/txnview.php` | `ADMIN` | `INTENTIONALLY_REPLACED` | A single-transaction view of the same table as `paypal`. Filtering the transactions view by `txn_id` is the same thing with one endpoint instead of two. |

### `donate`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `complete` | `modules/donate/complete.php` | `ANYONE` | `VERIFIED` | The return URL, which deliberately credits nothing — a redirect in the payer's browser proves nothing. |
| `history` | `modules/donate/history.php` | `NORMAL` | `VERIFIED` | The account's own donations. |
| `index` | `modules/donate/index.php` | `ANYONE` | `VERIFIED` | Publishes only the public half of the configuration. Off by default: crediting cannot be undone in practice. |
| `notify` | `modules/donate/notify.php` | `ANYONE` | `VERIFIED` | The only unauthenticated write in the application. Verified with the provider over HTTPS (the legacy used a raw socket on port 80), checked for status, receiver, currency and idempotency, and failing closed on an unreachable verifier. 21 tests. |
| `trusted` | `modules/donate/trusted.php` | `NORMAL` | `VERIFIED` | The account's own trusted payer addresses, populated automatically when a held payment clears. The legacy action was the player's own list, not an admin screen. |
| `update` | `modules/donate/update.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | `Flux::processHeldCredits()` behind an HTTP endpoint guarded by the installer password. It is `panel:release-held-credits`, scheduled hourly — crediting accounts is not something a web request should ask for. The hold queue it drives is what makes a chargeback survivable. |

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
| `emblem` | `modules/guild/emblem.php` | `ANYONE` | `VERIFIED` | Decodes rAthena's gzip-compressed BMP, stored as hex, to PNG with magenta turned into transparency. PHP's own `imagecreatefrombmp` replaces FluxCP's hand-written decoder. Malformed data is a 404, not an error — the column holds whatever the client uploaded. 17 tests. |
| `export` | `modules/guild/export.php` | `ADMIN` | `VERIFIED` | Streamed CSV, capped. |
| `index` | `modules/guild/index.php` | `LOWGM` | `VERIFIED` | Directory with member counts, searchable and sortable. LOWGM, as in the legacy map. |
| `view` | `modules/guild/view.php` | `NORMAL` | `VERIFIED` | Roster, allies, enemies and castles. |

### `history`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `cplogin` | `modules/history/cplogin.php` | `NORMAL` | `VERIFIED` | `cp_loginlog.error_code` holds FluxCP's Flux_LoginError integers, which LoginFailure preserves. An unmodelled code reports as "Refused" rather than being guessed at. |
| `emailchange` | `modules/history/emailchange.php` | `NORMAL` | `VERIFIED` | 13 tests cover the history module. |
| `gamelogin` | `modules/history/gamelogin.php` | `NORMAL` | `VERIFIED` | rAthena's `loginlog` has no account id and records the name typed, so the match follows the login server's case sensitivity. Both directions are tested. |
| `index` | `modules/history/index.php` | `NORMAL` | `INTENTIONALLY_REPLACED` | A menu with no data of its own in the legacy panel; a client route here. |
| `passchange` | `modules/history/passchange.php` | `NORMAL` | `VERIFIED` | Scoped to the session, never to an id from the request. |
| `passreset` | `modules/history/passreset.php` | `NORMAL` | `VERIFIED` | Nothing from `cp_resetpass.code` reaches the response, and a test asserts it. |

### `install`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/install/index.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | File-ledger schema installer replaced by Laravel migrations; see D4. |
| `reinstall` | `modules/install/reinstall.php` | `ADMIN` | `INTENTIONALLY_REPLACED` | As above; see D4. |

### `ipban`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/ipban/add.php` | `ADMIN` | `VERIFIED` | `POST /api/ip-bans`. The whitelist is checked first: a pattern covering the operator's own range locks every administrator out of the panel *and* the game. Needs ModifyIpBan on top of the route level. |
| `edit` | `modules/ipban/edit.php` | `ADMIN` | `VERIFIED` | `PUT /api/ip-bans/{pattern}`. The edit is recorded in `cp_ipbanlog`, so widening a ban leaves a trace of who did it and why. Cannot widen onto the whitelist. |
| `index` | `modules/ipban/index.php` | `ADMIN` | `VERIFIED` | `GET /api/ip-bans`. Expired rows are kept and flagged rather than hidden. 21 tests cover the module. |
| `remove` | `modules/ipban/remove.php` | `ADMIN` | `INTENTIONALLY_REPLACED` | Folded into `unban`, which already took a list. A single-item removal is a list of one, and two endpoints doing the same write is how their audit rows diverge. |
| `unban` | `modules/ipban/unban.php` | `ADMIN` | `VERIFIED` | `DELETE /api/ip-bans`, taking a list as the legacy did. Deletes the `ipbanlist` row — current state the login server reads — and adds a `cp_ipbanlog` row. Patterns that were not banned are reported rather than ignored. Needs RemoveIpBan. |

### `item`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/item/index.php` | `ANYONE` | `VERIFIED` | `GET /api/items` plus `/items`. Reads through the merge (D6), so the server's own `item_db2` entries appear with their custom stats. Legacy filters preserved — name, type, equip location, job, class, numeric comparisons, refineable, stock-versus-custom — but operators arrive named (`gt`, not `>`) and column names come from the configured maps, so no part of a request becomes SQL. 23 tests. |
| `iteminfo` | `modules/item/iteminfo.php` | `ADMIN` | `VERIFIED` | `GET/POST/DELETE /api/admin/items/descriptions`. The itemInfo.lua importer: an operator uploads the file their client reads and the in-game descriptions appear on item pages, stored in `cp_itemdesc`. An earlier revision of this row described the filter-vocabulary endpoint instead and marked the action done, which was wrong — the importer was not ported at all, and `cp_itemdesc` was created by the installer and read by nothing. The parser is line-at-a-time rather than the legacy's read-the-whole-file; `unidentifiedDescriptionName` ends with `identifiedDescriptionName`, so matching it needs a lookbehind or every item gets the "identify this with a Magnifier" text. The stored value is the one HTML in the project: the item's own text is escaped first and the only markup added is a colour span built from six matched hex digits, so a booby-trapped file cannot reach a page as markup. 19 tests. |
| `view` | `modules/item/view.php` | `ANYONE` | `VERIFIED` | `GET /api/items/{id}` plus `/items/{id}`. rAthena's ~100 boolean attribute columns are decoded into labelled lists by AttributeDecoder; `job_all` collapses rather than listing 26 jobs. The item script and the imported description are sent only here, not in the listing — one description lookup per row would make the listing the most expensive page in the application. The description honours FluxCP's `ShowItemDesc` as `panel.items.show_descriptions`. |

### `itemshop`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/itemshop/add.php` | `ADMIN` | `VERIFIED` | Needs AddShopItem on top of the route level, as the legacy access map had it. |
| `delete` | `modules/itemshop/delete.php` | `ADMIN` | `VERIFIED` | Needs DeleteShopItem. Withdrawing an item leaves `cp_redeemlog` alone — those rows are purchases somebody may not have collected. |
| `edit` | `modules/itemshop/edit.php` | `ADMIN` | `VERIFIED` | Needs EditShopItem. |
| `imagedel` | `modules/itemshop/imagedel.php` | `ADMIN` | `VERIFIED` | Removes the item image from the configured disk. |

### `logdata`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `branch` | `modules/logdata/branch.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `cashpoints` | `modules/logdata/cashpoints.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `char` | `modules/logdata/char.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) Read from the char/map connection, where rAthena keeps it. |
| `chat` | `modules/logdata/chat.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) The most privacy-sensitive table in the database. |
| `command` | `modules/logdata/command.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `feeding` | `modules/logdata/feeding.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `index` | `modules/logdata/index.php` | `ADMIN` | `INTENTIONALLY_REPLACED` | A menu with no data of its own; see `cplog.index`. |
| `inter` | `modules/logdata/inter.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) Char/map connection. |
| `login` | `modules/logdata/login.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `mvp` | `modules/logdata/mvp.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `npc` | `modules/logdata/npc.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `pick` | `modules/logdata/pick.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |
| `zeny` | `modules/logdata/zeny.php` | `ADMIN` | `VERIFIED` | One of the 20 declared views in `config/log_browsers.php`, read by one controller. Columns are intersected with the real schema, so a differently configured server gets a narrower table and an absent one reports `available: false` rather than a 500. Administrator, as in the legacy. 21 tests cover all of them. (D21) |

### `mail`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/mail/index.php` | `ADMIN` | `VERIFIED` | Answers with a recipient count first and sends only when the request repeats it back; a count that changed in between is refused. Server accounts and unusable addresses are excluded. 10 tests cover this and the terms. |

### `main`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/main/index.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | The legacy CMS front page. The home page is composed from blocks declared in the active theme's `theme.json` instead, with the news and statistics it showed served by their own endpoints. See D7. |
| `page_not_found` | `modules/main/page_not_found.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | A server-rendered 404 page. The SPA router renders `NotFoundPage` and Laravel answers unmatched API paths; there is no template to port. |
| `preprocess` | `modules/main/preprocess.php` | `ANYONE` | `INTENTIONALLY_REPLACED` | A hook that ran before every request: it probed every game server, pruned unconfirmed accounts and released held credits as a side effect of somebody loading a page. Replaced by middleware (`ResolveServerGroup`) and three scheduled commands. See D14 for why the status probe moved. |

### `monster`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/monster/index.php` | `ANYONE` | `VERIFIED` | Merged (D6). Column names are resolved per server rather than hardcoded: rAthena has shipped `ID`/`iName`/`LV` and `id`/`name_english`/`level`, and assuming one renders an empty listing against the other. 10 tests. |
| `view` | `modules/monster/view.php` | `ANYONE` | `VERIFIED` | Modes decoded from the per-mode boolean columns. |

### `news`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/news/add.php` | `ADMIN` | `VERIFIED` | `POST /api/news`. The byline defaults to the account writing it rather than being free text. `link` is restricted to http and https. (D20) |
| `delete` | `modules/news/delete.php` | `ADMIN` | `VERIFIED` | `DELETE /api/news/{id}`. |
| `edit` | `modules/news/edit.php` | `ADMIN` | `VERIFIED` | Split into `GET /api/news/{id}/edit` and `PUT /api/news/{id}`. Editing leaves `created` alone — it orders the front page, and moving it would push an old article back to the top for a typo fix. |
| `index` | `modules/news/index.php` | `ANYONE` | `VERIFIED` | Public listing from `cp_cmsnews`, newest first, paginated. Excerpts are derived with tags stripped; the stored rich text is returned only for a single article. No category or thumbnail, because the legacy schema has neither. 16 tests shared with the other site-data endpoints. News comes from the panel's table or, where an operator sets `panel.news.source` to `feed`, from an external RSS or Atom feed — FluxCP's `CMSNewsType = 2`, which an earlier revision of this port did not have. The feed is cached with the last good result served while it is unreachable, has a timeout and a size cap, is parsed with `LIBXML_NONET`, and has every field stripped to plain text: a description is HTML by specification and it is somebody else's. 13 tests. |
| `manage` | `modules/news/manage.php` | `ADMIN` | `VERIFIED` | `GET /api/news/manage`. Paginated and sortable; omits bodies. |
| `view` | `modules/news/view.php` | `ANYONE` | `VERIFIED` | Single article including its body. |

### `pages`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/pages/add.php` | `ADMIN` | `VERIFIED` | `POST /api/pages`. Paths are validated to letters, digits, dashes and slashes, which refuses anything readable as a traversal or query string. |
| `content` | `modules/pages/content.php` | `ANYONE` | `VERIFIED` | `GET /api/pages/{path}`. Public, reached by path so a page keeps a stable URL across edits. Paths are normalised, which fixes the legacy's exact-match lookup leaving a page unreachable when its link and row disagreed about a slash or a capital. Nested paths work. |
| `delete` | `modules/pages/delete.php` | `ADMIN` | `VERIFIED` | `DELETE /api/pages/{id}`. 20 tests cover news and pages. (D20) |
| `edit` | `modules/pages/edit.php` | `ADMIN` | `VERIFIED` | `PUT /api/pages/{id}`. |
| `index` | `modules/pages/index.php` | `ADMIN` | `VERIFIED` | `GET /api/pages`. Administrator; omits bodies. |

### `purchase`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `add` | `modules/purchase/add.php` | `ANYONE` | `VERIFIED` | `POST /api/shop/cart`, capped at the configured maximum quantity. |
| `cart` | `modules/purchase/cart.php` | `NORMAL` | `VERIFIED` | Session cart, as the legacy had it. An item withdrawn from sale is reported rather than silently dropped. |
| `checkout` | `modules/purchase/checkout.php` | `NORMAL` | `VERIFIED` | `POST /api/shop/checkout`. Part of the credit shop. The deduction is a conditional UPDATE inside a transaction, so a replayed or concurrent checkout cannot spend the same credits twice. Purchases are collected in game; the panel never writes to an inventory. 19 tests. |
| `clear` | `modules/purchase/clear.php` | `NORMAL` | `VERIFIED` | `DELETE /api/shop/cart`. |
| `index` | `modules/purchase/index.php` | `ANYONE` | `VERIFIED` | `GET /api/shop`. Item names come through the merge (D6). Part of the credit shop. The deduction is a conditional UPDATE inside a transaction, so a replayed or concurrent checkout cannot spend the same credits twice. Purchases are collected in game; the panel never writes to an inventory. 19 tests. |
| `pending` | `modules/purchase/pending.php` | `NORMAL` | `VERIFIED` | `GET /api/shop/pending`. Purchases wait here until an rAthena script hands them over in game. |
| `remove` | `modules/purchase/remove.php` | `NORMAL` | `VERIFIED` | `DELETE /api/shop/cart/item`. |

### `ranking`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `alchemist` | `modules/ranking/alchemist.php` | `UNLISTED` | `VERIFIED` | Fame ladder. Includes the rebirth, baby and third-class ids — a ladder matching only class 18 is empty on a mature server. |
| `blacksmith` | `modules/ranking/blacksmith.php` | `UNLISTED` | `VERIFIED` | As alchemist, with the blacksmith branch ids. |
| `character` | `modules/ranking/character.php` | `ANYONE` | `VERIFIED` | Level ladder with the ban, staff, deletion and inactivity filters. Handles the login/char cross-database join the legacy panel assumed was always possible. 12 tests shared with the zeny ladder. |
| `death` | `modules/ranking/death.php` | `ANYONE` | `VERIFIED` | The count lives in `char_reg_num` under `PC_DIE_COUNTER`, not as a column. Left join coalescing to zero, so a character who has never died is still listed — an inner join would drop them. |
| `guild` | `modules/ranking/guild.php` | `ANYONE` | `VERIFIED` | Takes the greater of `guild.exp` and the sum of its members, as the legacy did: some scripts credit members without updating the column. |
| `homunculus` | `modules/ranking/homunculus.php` | `UNLISTED` | `VERIFIED` | Ranks homunculi, joining back for the owner. Excludes released ones, which stay in the table with `alive = 0`. |
| `mvp` | `modules/ranking/mvp.php` | `ANYONE` | `VERIFIED` | Assembled across the logs, char/map and reference connections rather than joined — `mvplog` is commonly on another host, where a cross-database join would not run. Over-fetches before removing staff kills so the ladder is not left short. 20 tests cover all six ladders. |
| `zeny` | `modules/ranking/zeny.php` | `ANYONE` | `VERIFIED` | As above, plus the per-character HideFromZenyRanking opt-out. |

### `server`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `info` | `modules/server/info.php` | `ANYONE` | `VERIFIED` | `GET /api/server/info`. Accounts, characters, guilds, parties, total zeny and the world's declared rates. Parties report null rather than zero when the table is absent, and the rates carry a flag saying whether the operator declared them — rAthena keeps them in conf files the panel cannot read. |
| `status` | `modules/server/status.php` | `ANYONE` | `VERIFIED` | Per-process reachability, live and peak player counts, WoE state. Measurement moved off the request path and broadcast over Reverb (D14). Peak now read from the correct database. 5 tests. |
| `status-xml` | `modules/server/status-xml.php` | `ANYONE` | `VERIFIED` | Kept at its original element and attribute names. Listing sites, Discord bots and forum widgets parse them, so renaming would be the same as removing the endpoint. |

### `service`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `tos` | `modules/service/tos.php` | `ANYONE` | `VERIFIED` | An ordinary CMS page at a configured path, rather than the template-on-disk the legacy rendered. A server with none gets an honest "not published". |

### `servicedesk`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `catcontrol` | `modules/servicedesk/catcontrol.php` | `HIGHGM` | `VERIFIED` | Categories are hidden rather than deleted: tickets reference them by id, so removing one leaves historic tickets pointing at nothing. |
| `create` | `modules/servicedesk/create.php` | `NORMAL` | `VERIFIED` | `POST /api/support/tickets`. Part of the support desk. A player sees their own tickets and nobody else's; the ticket owner and originating address come from the session, which the legacy read from the submitted form. 19 tests. |
| `index` | `modules/servicedesk/index.php` | `NORMAL` | `VERIFIED` | `GET /api/support/tickets`. Part of the support desk. A player sees their own tickets and nobody else's; the ticket owner and originating address come from the session, which the legacy read from the submitted form. 19 tests. |
| `staffindex` | `modules/servicedesk/staffindex.php` | `LOWGM` | `VERIFIED` | `GET /api/support/queue`. |
| `staffsettings` | `modules/servicedesk/staffsettings.php` | `LOWGM` | `VERIFIED` | Each staff member's own preferences, including the name their replies are signed with. |
| `staffview` | `modules/servicedesk/staffview.php` | `LOWGM` | `VERIFIED` | `GET /api/support/queue/{id}`, plus a reply route the legacy folded into it. Staff replies are signed with a chosen display name. |
| `staffviewclosed` | `modules/servicedesk/staffviewclosed.php` | `LOWGM` | `VERIFIED` | The same listing with `closed` forced on by a route default, which wins over the query parameter — its separate permission cannot be used to reach the live queue. |
| `view` | `modules/servicedesk/view.php` | `NORMAL` | `VERIFIED` | `GET /api/support/tickets/{id}`. Somebody else's ticket is a 404, not a 403 — a 403 confirms it exists, which over a range of ids counts every ticket the server has had. |

### `unauthorized`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/unauthorized/index.php` | `UNLISTED` | `INTENTIONALLY_REPLACED` | A page with a meta-refresh redirect. The permission middleware answers 401 or 403 and the client renders it; there is no server-rendered page to port. |

### `vending`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/vending/index.php` | `ANYONE` | `VERIFIED` | Open stalls, searchable by title and map. |
| `viewshop` | `modules/vending/viewshop.php` | `ANYONE` | `VERIFIED` | Stock read through the seller's cart, where the item id, refine and cards live. Item names come from the merge. |

### `webcommands`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `index` | `modules/webcommands/index.php` | `ADMIN` | `VERIFIED` | Off unless enabled, and only commands matching the operator's allow-list are queued — the table is read by a script with game-master powers and the legacy inserted whatever was submitted. An empty allow-list accepts nothing. (D23) |

### `woe`

| Action | Legacy file | Access | Status | Notes |
| --- | --- | --- | --- | --- |
| `custom` | `modules/woe/custom.php` | `UNLISTED` | `VERIFIED` | Reads `$sday`/`$eday`/`$stime`/`$etime` from `mapreg`, which is how a common rAthena setup lets a script change the siege window without a restart. A server not using the convention gets an empty schedule. |
| `index` | `modules/woe/index.php` | `ANYONE` | `VERIFIED` | The siege schedule per world, with its timezone and the next absolute start. |

## Core libraries (`legacy/lib/Flux/`)

| Legacy class | Responsibility | Replacement | Status |
| --- | --- | --- | --- |
| `Flux` | Static config/registry god-object + helpers | Laravel config + dedicated services | `IMPLEMENTING` |
| `Flux_Config` | Dot-notation array wrapper | `Illuminate\Config` / typed value objects | `INTENTIONALLY_REPLACED` |
| `Flux_Connection` | Multi-database PDO wrapper (main/logs/web) | Runtime-registered Laravel connections (D5) | `VERIFIED` |
| `Flux_Connection_Statement` | PDO statement wrapper with encoding conversion | Query builder / PDO via Laravel | `INTENTIONALLY_REPLACED` |
| `Flux_Dispatcher` | module/action router + auth gate | Laravel router + permission middleware | `VERIFIED` |
| `Flux_Template` | View renderer **and** 57 view helpers | Blade shell + Vue components + API resources | `IMPLEMENTING` |
| `Flux_Authorization` | Access-level checks, `allowedTo*` magic getters | Permission registry + Gates/Policies (D3) | `VERIFIED` |
| `Flux_SessionData` | Session state (account, server, theme, messages) | Laravel session + authenticated user | `IMPLEMENTING` |
| `Flux_DataObject` | Array-to-object row wrapper | Eloquent models | `INTENTIONALLY_REPLACED` |
| `Flux_LoginServer` | Auth, registration, bans, credits, prefs | Split into auth, ban, credit and preference services | `IMPLEMENTING` |
| `Flux_CharServer / Flux_MapServer / Flux_BaseServer` | TCP reachability probe via `fsockopen` | Server status service + cached probe | `VERIFIED` |
| `Flux_Athena / Flux_LoginAthenaGroup` | Server-group containers | Server-group registry (D5) | `VERIFIED` |
| `Flux_TemporaryTable` | Destructive item/mob table merge | Derived-table merge, no DDL (D6) | `INTENTIONALLY_REPLACED` |
| `Flux_Paginator` | Sortable/filterable SQL pagination | `ListQuery` plus Laravel pagination; the client renders its own controls | `VERIFIED` |
| `Flux_Installer*` | File-ledger schema installer (4 classes) | `panel:install-schema` command (D4) | `VERIFIED` |
| `Flux_Captcha` | GD CAPTCHA generator | `ChallengesHumanity` with native and reCAPTCHA drivers | `VERIFIED` |
| `Flux_EmblemExporter` | Guild emblem BMP/GIF conversion | `GuildEmblemService` | `VERIFIED` |
| `Flux_ItemShop / Flux_ItemShop_Cart` | Credit shop + session cart | `App\Services\Shop\ItemShop` | `VERIFIED` |
| `Flux_PaymentNotifyRequest` | PayPal IPN verification and crediting | `PaymentNotification` + `DonationService`, with a hold queue | `VERIFIED` |
| `Flux_Mailer` | PHPMailer wrapper | Laravel Mail mailables via `AccountMailer` | `INTENTIONALLY_REPLACED` |
| `Flux_LogFile` | Plain-text file logger | Laravel logging channels | `INTENTIONALLY_REPLACED` |
| `Flux_Addon` | Add-on discovery and config merge | Laravel packages (D8) | `INTENTIONALLY_REPLACED` |
| `Flux_Error and 6 error subclasses` | Exception hierarchy | Typed exceptions, enums and Laravel's handler | `INTENTIONALLY_REPLACED` |
| `lib/functions/discordwebhook` | Discord webhook notifications | `DiscordWebhook` service | `VERIFIED` | All five events the legacy announced: a registration, a new ticket, a web command, a mass mailing and an unhandled exception. The legacy was a bare cURL POST with no timeout, run inline and its result discarded, built by string concatenation from whatever triggered it — so a slow Discord made registration slow, a broken webhook was indistinguishable from a quiet channel, and an account named `@everyone` pinged the whole server on registration. Here: a timeout, a logged failure that never records the URL (it carries its own token), `allowed_mentions` set to parse nothing, https only, and optional queueing. The exception event is off by default, unlike the legacy — an exception message is where a credential ends up. 17 tests. |
| `lib/functions/getReposVersion` | Upstream version check | Dropped (phones home) | `INTENTIONALLY_REPLACED` |
| `lib/functions/imagecreatefrombmpstring` | BMP decoding for emblems | PHP's own `imagecreatefrombmp` (7.2+) | `INTENTIONALLY_REPLACED` |
| `lib/phpmailer (49 files)` | Bundled PHPMailer 5.x | Laravel Mail (Symfony Mailer) | `INTENTIONALLY_REPLACED` |

## Cross-cutting concerns

| Concern | Legacy | Status | Notes |
| --- | --- | --- | --- |
| Routing | `Flux_Dispatcher` module/action | `VERIFIED` | API routes + SPA history routing. 112 named API endpoints. |
| Authentication | `Flux_LoginServer::isAuth()` | `VERIFIED` | Session cookie auth in the web middleware group (D11); rAthena credential compatibility (D1). Registration, confirmation, password reset, password change and e-mail change are all built and tested. |
| Authorization | `access.php` 139 action keys + 46 features | `VERIFIED` | Permission registry with 143 route and 48 ability entries, deny-by-default (D3). Gates registered for every ability. Two abilities were declared and enforced nowhere — `SeeUnknownItems` and `ViewGuild` — which is what pointed at the dropped character and guild data. |
| Database connections | 4 handles per server group | `VERIFIED` | Runtime registration (D5), plus co-location detection for the login/char join. |
| Application schema | 25 `cp_*` tables, 44 SQL files | `VERIFIED` | `panel:install-schema` (D4). 196 columns compared against the legacy end state; 11 benign type differences recorded in the compatibility report. |
| Game reference data | 26 vocabulary files under `config/` | `VERIFIED` | `config/rathena_reference.php`: 23 vocabularies, 833 entries. Ten were missing and were found by reconciling the legacy's files one by one rather than by name — monster races, sizes, elements and AI, item subtypes, the random option names, the pick and feeding log codes, the sign-in outcomes, and the equip location combinations. Their absence was visible in the product: a monster's race rendered as a number, a pick log read `M` where it meant Monster, a sign-in outcome read `2`, a two-handed sword listed "Right Hand, Left Hand", and the random options this port had just added showed bare ids. Races, sizes and elements are keyed by both the number and the word, because rAthena stores them each way depending on the table. |
| Configuration | 361 options in one array | `IMPLEMENTING` | Split by audience (D12). The options the built features read are ported; database-backed admin-editable settings are not built. |
| Themes | PHP template inheritance, 3 themes | `VERIFIED` | Design tokens plus file-resolution overrides for pages, layouts and components, selected by APP_THEME (D7). Two themes ship. Covered by 28 tests, including that the API is byte-identical whichever theme is active and that no theme file fetches data. |
| Add-ons | `addons/` loader, 1 example | `NOT_STARTED` | Laravel packages (D8). |
| Game branding | Hardcoded strings + SiteName config | `VERIFIED` | config/game.php consumed through useGame(). A test fails if any .vue file hardcodes the game name. |
| Localisation | 4 languages, `Flux::message()` | `IMPLEMENTING` | `lang/en` covers the authentication and account messages; before it, every `trans()` in the sign-in path returned its own key to the visitor. The other three upstream languages are not ported, and most interface copy is still inline in the Vue components. |
| Mail | Bundled PHPMailer 5.x | `VERIFIED` | Five mailables with HTML and text parts: four account ones through `AccountMailer`, plus the operator's broadcast. Account mail is inline by default and queued behind `PANEL_QUEUE_MAIL`; a broadcast is always queued. |
| Realtime | None (XML status feed, page refresh) | `VERIFIED` | Reverb broadcasting with a polling fallback (D14). Verified end to end with a WebSocket client. |
| Queues/scheduling | Inline in `preprocess` on every request | `VERIFIED` | Three scheduled commands: status measurement, unconfirmed-account pruning, and the donation hold queue. None of the three is reachable over HTTP, which two of them were. |
| Server status | `fsockopen` per request | `VERIFIED` | Cached probe behind a contract, measured on a schedule and broadcast. |
| Pagination/sorting | `Flux_Paginator`, allow-listed columns, direction from request | `VERIFIED` | `ListQuery`: an allow-list per endpoint mapping public names onto columns, a capped `per_page`, and nulls last on an ascending sort as the legacy did. In use by every listing. Not a security fix — the legacy allow-list was always hardcoded by the calling module. |
| Automated tests | None in repository | `IMPLEMENTING` | 779 PHPUnit tests, 2,693 assertions, across 47 files. Integration tests run against a real MariaDB schema. **No frontend tests exist**, which is the largest gap in the project and matters most now that the interface is the next thing to change. |

