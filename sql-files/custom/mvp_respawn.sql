--
-- Table structure for persistent MVP respawn timers
--
-- Used by the `mvp_respawn_persist` battle config (conf/battle/monster.conf).
-- The map-server auto-creates this table on boot, so importing it manually is
-- optional; it is provided here for reference / manual setup.
--
-- Stores, per MVP spawn (mob id + map + spawn coordinates), the unix timestamp
-- at which the MVP is next due to respawn. Restored on boot / @reloadscript.
--

CREATE TABLE IF NOT EXISTS `mvp_respawn` (
  `mob_id` INT UNSIGNED NOT NULL,
  `map` VARCHAR(32) NOT NULL,
  `x` SMALLINT UNSIGNED NOT NULL DEFAULT '0',
  `y` SMALLINT UNSIGNED NOT NULL DEFAULT '0',
  `respawn_time` INT UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`mob_id`,`map`,`x`,`y`)
) ENGINE=MyISAM;
