# Database Analysis

What the legacy panel expects from the database, verified by executing the schemas rather than reading them. Every table and column below was materialised into a throwaway reference database from:

- rAthena `sql-files/` (`main.sql`, `logs.sql`, `web.sql`, `item_db*`, `mob_db*`, `mob_skill_db*`)
- FluxCP `legacy/data/schemas/` (44 versioned files, applied in version order)

> **The live `ragnarok` database on this machine was treated as read-only throughout.** It holds real accounts and characters. Nothing in this project writes to it; the audit used disposable `fluxcp_ref` / `fluxcp_ref_logs` databases.

## 1. Connection topology

FluxCP does not have one database. `config/servers.php` is a list of *server groups*, and each group declares four connection configs and one-to-many char/map server pairs:

```
servers.php = [ server group, server group, ... ]

server group
├── DbConfig       -> the login + char/map database   ("main")
├── LogsDbConfig   -> the game logs database          (often a separate host)
├── WebDbConfig    -> rAthena's web database
├── LoginServer    -> exactly one  (address, port, UseMD5, NoCase, GroupID)
└── CharMapServers -> one or more  (CharServer + MapServer address/port, rates, WoE times)
```

In code these are reached as `{$server->loginDatabase}`, `{$server->charMapDatabase}`, `{$server->logsDatabase}` and `{$server->webDatabase}`, interpolated straight into SQL as cross-database references. Statements for the logs database go through a separate accessor (`getStatementForLogs()`) because it may live on another server entirely.

Consequences for the port, which Laravel does not support out of the box:

1. The number of connections is **not known until config is read**, so connections must be
   registered at runtime, not declared statically in `config/database.php`.
2. A single request may touch several databases, and the active server group is a
   **per-session** choice (`preferred_server`).
3. Login and char/map default to the *same* database but may be split, so the port must
   not assume a join between `login` and `char` is always legal.

## 2. rAthena tables the panel reads or writes

Extracted by scanning every `modules/` and `lib/` query for interpolated database handles.

### `login` — Login database (`DbConfig`, or `LoginServer.Database`)

| Table | Exists in rAthena schema | Columns |
| --- | --- | --: |
| `login` | yes | 20 |
| `ipbanlist` | yes | 4 |

### `charmap` — Char/map database (`DbConfig`, or per-pair `Database`)

| Table | Exists in rAthena schema | Columns |
| --- | --- | --: |
| `char` | yes | 80 |
| `char_reg_num` | yes | 4 |
| `charlog` | yes | 14 |
| `interlog` | yes | 3 |
| `friends` | yes | 2 |
| `party` | yes | 6 |
| `pet` | yes | 13 |
| `homunculus` | yes | 24 |
| `inventory` | yes | 33 |
| `cart_inventory` | yes | 31 |
| `storage` | yes | 31 |
| `guild` | yes | 17 |
| `guild_member` | yes | 4 |
| `guild_position` | yes | 5 |
| `guild_alliance` | yes | 4 |
| `guild_expulsion` | yes | 5 |
| `guild_castle` | yes | 18 |
| `guild_storage` | yes | 31 |
| `guild_emblems` | yes | 5 |
| `mapreg` | yes | 3 |
| `vendings` | yes | 12 |
| `vending_items` | yes | 5 |
| `buyingstores` | yes | 13 |
| `buyingstore_items` | yes | 5 |
| `item_db` | yes | 101 |
| `item_db_re` | yes | 111 |
| `item_db2` | yes | 101 |
| `item_db2_re` | yes | 111 |
| `mob_db` | yes | 166 |
| `mob_db_re` | yes | 168 |
| `mob_db2` | yes | 166 |
| `mob_db2_re` | yes | 168 |
| `mob_skill_db` | yes | 19 |
| `mob_skill_db_re` | yes | 19 |
| `mob_skill_db2` | yes | 19 |
| `mob_skill_db2_re` | yes | 19 |

### `logs` — Logs database (`LogsDbConfig`)

| Table | Exists in rAthena schema | Columns |
| --- | --- | --: |
| `picklog` | yes | 30 |
| `zenylog` | yes | 7 |
| `mvplog` | yes | 7 |
| `atcommandlog` | yes | 7 |
| `branchlog` | yes | 6 |
| `cashlog` | yes | 7 |
| `chatlog` | yes | 11 |
| `feedinglog` | yes | 11 |
| `loginlog` | yes | 5 |
| `npclog` | yes | 7 |

Two further table names appear in the panel's SQL but are **not** rAthena tables:

- `items` and `monsters` are **MySQL `TEMPORARY` tables** built per request by
  `Flux_TemporaryTable`. It merges `item_db` + `item_db_re` + `item_db2` + `item_db2_re`
  (and the `mob_db` family) with later tables destructively overriding earlier rows, so the
  panel sees one unified item/mob view in which custom entries win. This is relied on by
  `item/index`, `item/view`, `monster/*`, `character/view`, `ranking/mvp`,
  `buyingstore/viewshop`, `purchase/pending` and four `logdata` actions. A port that queries
  `item_db` directly instead will silently return pre-renewal rows and ignore custom items.

A caveat on the live tables: `vendings`, `vending_items`, `buyingstores` and
`buyingstore_items` are defined in `main.sql` but only populated while the map server is
running with those features enabled, so the `vending` and `buyingstore` modules legitimately
show nothing on an idle server.

## 3. Application-owned tables (`cp_*`)

25 tables, created and migrated by FluxCP itself, not by rAthena. Names are indirected through the `FluxTables` config map, so every one is renameable. Column counts below are the resolved final state after all versioned deltas.

| Table | Config key | Cols | Purpose |
| --- | --- | --: | --- |
| `cp_banlog` | `AccountBanTable` | 7 | Account ban/unban audit trail (ban_type 0=unban, 1=temporary, 2=permanent). |
| `cp_charprefs` | `CharacterPrefsTable` | 6 | Arbitrary per-character key/value preferences. |
| `cp_cmsnews` | `CMSNewsTable` | 7 | News CMS entries. |
| `cp_cmspages` | `CMSPagesTable` | 5 | Static page CMS entries. |
| `cp_cmssettings` | `CMSSettingsTable` | 2 | CMS key/value settings. |
| `cp_commands` | `WebCommandsTable` | 6 | Queued remote at-commands for the map server to consume. |
| `cp_createlog` | `AccountCreateTable` | 12 | Account registration audit, e-mail confirmation code/expiry. **Stores a copy of the password.** |
| `cp_credits` | `CreditsTable` | 4 | Per-account credit balance plus last-donation marker. Currency for the item shop. |
| `cp_emailchange` | `ChangeEmailTable` | 10 | E-mail change requests and confirmation codes. |
| `cp_ipbanlog` | `IpBanTable` | 7 | IP ban/unban audit trail, paired with rAthena `ipbanlist`. |
| `cp_itemdesc` | `ItemDescTable` | 2 | Admin-authored item description overrides. |
| `cp_itemshop` | `ItemShopTable` | 8 | Item-shop catalogue: item, category, quantity, credit cost, description, image flag. |
| `cp_loginlog` | `LoginLogTable` | 7 | Control-panel login attempts. **Stores the submitted password.** |
| `cp_loginprefs` | `AccountPrefsTable` | 5 | Arbitrary per-account key/value preferences. |
| `cp_onlinepeak` | `OnlinePeakTable` | 3 | Daily peak online-player counts. |
| `cp_pwchange` | `ChangePasswordTable` | 6 | Password change audit. **Stores old and new passwords.** |
| `cp_redeemlog` | `RedemptionTable` | 11 | Item-shop purchases awaiting or completed in-game delivery. |
| `cp_resetpass` | `ResetPasswordTable` | 10 | Password-reset requests and codes. **Stores old and new passwords.** |
| `cp_servicedesk` | `ServiceDeskTable` | 15 | Support tickets. |
| `cp_servicedeska` | `ServiceDeskATable` | 8 | Support ticket replies/answers. |
| `cp_servicedeskcat` | `ServiceDeskCatTable` | 3 | Support ticket categories. |
| `cp_servicedesksettings` | `ServiceDeskSettingsTable` | 6 | Support desk configuration. |
| `cp_trusted` | `DonationTrustTable` | 5 | Donors trusted to bypass donation hold/review. |
| `cp_txnlog` | `TransactionTable` | 34 | Raw PayPal IPN transaction records (34 columns mirroring the IPN payload). |
| `cp_xferlog` | `CreditTransferTable` | 7 | Credit transfers between accounts. |

### Where they live

The installer splits them across two targets, which matters because the login and char/map
databases can be different servers:

- **login database** (18): `cp_banlog`, `cp_cmsnews`, `cp_cmspages`, `cp_cmssettings`, `cp_createlog`, `cp_credits`, `cp_emailchange`, `cp_ipbanlog`, `cp_loginlog`, `cp_loginprefs`, `cp_pwchange`, `cp_resetpass`, `cp_servicedesk`, `cp_servicedeska`, `cp_servicedeskcat`, `cp_servicedesksettings`, `cp_trusted`, `cp_txnlog`
- **char/map database** (7): `cp_charprefs`, `cp_commands`, `cp_itemdesc`, `cp_itemshop`, `cp_onlinepeak`, `cp_redeemlog`, `cp_xferlog`

## 4. Credentials and the password column

This is the single hardest constraint in the migration.

```
login.user_pass   varchar(32)   NOT NULL   DEFAULT ''
```

`Flux_LoginServer::isAuth()` authenticates with a direct column comparison:

```sql
SELECT userid FROM login
 WHERE sex != 'S' AND group_id >= 0
   AND LOWER(userid) = LOWER(?)      -- or CAST(userid AS BINARY) = ? when NoCase is off
   AND user_pass = ?                 -- md5($password) when UseMD5, otherwise cleartext
 LIMIT 1
```

So the stored value is either **cleartext** or **unsalted MD5**, selected per server by `LoginServer.UseMD5`. `Flux::hashPassword()` is literally `return md5($password);`.

Three facts make this non-negotiable rather than merely bad:

1. **rAthena's own login server reads this column.** The panel is not the only consumer, so   re-hashing it with bcrypt would stop players logging into the game.
2. **`varchar(32)` cannot physically hold a bcrypt hash** (60 chars) or an Argon2 hash.
3. On the live database inspected here, `UseMD5` is off: stored password lengths are 2, 4, 6
   and 16 characters — i.e. **cleartext**.

### Password copies in the audit tables

Four FluxCP tables keep their own copy of the password:

| Table | Column(s) | Written when |
| --- | --- | --- |
| `cp_createlog` | `user_pass` | every registration |
| `cp_loginlog` | `password` | every control-panel login attempt |
| `cp_pwchange` | `old_password`, `new_password` | every password change |
| `cp_resetpass` | `old_password`, `new_password` | every password reset |

Because the underlying value is usually cleartext, a default FluxCP install writes **every submitted password into the database in cleartext, including failed attempts**. The legacy mitigation is presentational only: `access.php` sets `SeeCpLoginLogPass`, `SeeCpResetPass`, `SeeCpChangePass`, `SearchMD5Passwords` and `SeeAccountPassword` to `NOONE`, which hides the columns in the UI while leaving the data in place.

How the port handles all of this is recorded in [MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) (decisions D1 and D2).

## 5. Schema versioning in the legacy installer

`data/schemas/{logindb,charmapdb}/<table>.<version>.sql` — the lowest version of each table
is a `CREATE TABLE IF NOT EXISTS`, and every later version is a delta. Deltas that add
columns are wrapped in a throwaway stored procedure with a `CONTINUE HANDLER FOR 1060`
(duplicate column) so re-running is harmless:

```sql
CREATE PROCEDURE cp_itemshop_20090104190020() BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE `cp_itemshop` ADD `category` INT(11) NULL AFTER `nameid`;
END;
```

Applied versions are tracked as **files on disk**, not in the database:
`data/logs/schemas/<logindb|charmapdb>/<ServerName>[/<CharMapName>]/`. Losing that
directory makes the installer believe the schema is unversioned. `main/preprocess` checks
`Flux_Installer::updateNeeded()` on **every request** and force-redirects to `install` when
anything is outstanding.

The port replaces this with Laravel migrations on a dedicated connection, keeping the ledger
in the database where it belongs. See decision D4.

