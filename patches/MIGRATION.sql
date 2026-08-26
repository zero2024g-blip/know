-- =====================================================================
--  Migrating an EXISTING database to this build
--  ---------------------------------------------------------------
--  Run once, against your live database, BEFORE putting the new panel
--  in front of users. Take a backup first.
--
--      mysqldump -u USER -p DBNAME > backup-before-migration.sql
--      mysql     -u USER -p DBNAME < MIGRATION.sql
--
--  Your four business tables (users, keys_code, referral_code,
--  history) are UNCHANGED. No column is added, renamed or dropped, and
--  no existing row is rewritten except where step 2 says so. Every
--  statement here is safe to run twice.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Two new tables: the rate limiters
-- ---------------------------------------------------------------------
-- These did not exist before. WITHOUT THEM THE PANEL STILL RUNS, and
-- that is the danger: every rate-limit query fails, the failure is
-- caught so the page keeps working, and login has no brute-force limit
-- at all while looking perfectly healthy. The code now logs this at
-- 'critical', but creating the tables is the actual fix.
--
--   auth_ratelimit  - failed logins and registrations, per IP
--   check_ratelimit - failed public key checks, per IP
--
-- Only a hash of the IP is stored, never the address itself.

CREATE TABLE IF NOT EXISTS `auth_ratelimit` (
  `ip_hash`       CHAR(32) NOT NULL,
  `fails`         INT NOT NULL DEFAULT 0,
  `window_end`    INT NOT NULL DEFAULT 0,
  `blocked_until` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip_hash`),
  KEY `idx_auth_rl_stale` (`blocked_until`, `window_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `check_ratelimit` (
  `ip_hash`       CHAR(32) NOT NULL,
  `fails`         INT NOT NULL DEFAULT 0,
  `window_end`    INT NOT NULL DEFAULT 0,
  `blocked_until` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip_hash`),
  KEY `idx_check_rl_stale` (`blocked_until`, `window_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 2. Close the old referral codes   <-- READ THIS ONE
-- ---------------------------------------------------------------------
-- The old code called useReferral($code) without a username, so the
-- guard `if ($code and $username)` was never true and used_by was
-- never written. Every referral row in your database therefore has
-- used_by = NULL, whether or not somebody already registered with it.
--
-- The new code treats "used_by is empty" as "this code is available".
-- So on the day you deploy, EVERY REFERRAL CODE YOU HAVE EVER ISSUED
-- becomes spendable again. Anyone who kept an old code could open a
-- fresh account and be credited its balance.
--
-- Nothing in the database records which codes were really used, so
-- they cannot be told apart. The safe move is to close all of them and
-- issue new ones for anybody still waiting to register.
--
-- Check what you have first:
--
--     SELECT COUNT(*) AS open_codes, SUM(set_saldo) AS money_at_risk
--     FROM referral_code
--     WHERE used_by IS NULL OR used_by = '';
--
-- Then run this:

UPDATE `referral_code`
   SET `used_by` = 'closed-at-migration'
 WHERE `used_by` IS NULL OR `used_by` = '';

-- If you would rather keep a specific code alive, re-open it by name
-- AFTER the statement above (put your own code in place of YOURCODE):
--
--     UPDATE referral_code SET used_by = NULL
--      WHERE code = MD5(CONCAT('<the pepper from nata_helper.php>', 'YOURCODE'));
--
-- Codes are stored hashed, not in plain text, which is why the lookup
-- looks like that. Creating a fresh code from the admin panel is
-- easier and is what I would do.


-- ---------------------------------------------------------------------
-- 3. Optional: indexes the new dashboard reads behind
-- ---------------------------------------------------------------------
-- The dashboard counters filter on registrator, status and
-- expired_date. On a small table this makes no difference; past a few
-- thousand keys it does. Skip if your table is small.
--
-- MySQL has no "CREATE INDEX IF NOT EXISTS", so these will error with
-- "Duplicate key name" if you already have them. That error is safe to
-- ignore.

-- ALTER TABLE `keys_code` ADD INDEX `idx_keys_registrator` (`registrator`);
-- ALTER TABLE `keys_code` ADD INDEX `idx_keys_status`      (`status`);
-- ALTER TABLE `keys_code` ADD INDEX `idx_keys_expired`     (`expired_date`);
-- ALTER TABLE `history`   ADD INDEX `idx_history_user_do`  (`user_do`);


-- ---------------------------------------------------------------------
-- 4. Confirm it worked
-- ---------------------------------------------------------------------
--     SHOW TABLES LIKE '%ratelimit%';          -- expect 2 rows
--     SELECT COUNT(*) FROM referral_code
--      WHERE used_by IS NULL OR used_by = '';  -- expect 0
--
-- Then sign in to the panel and get one password wrong six times. The
-- sixth attempt must tell you to wait. If it does not, auth_ratelimit
-- is not being written to — check writable/logs/ for a line containing
-- "Rate limiter unavailable".

-- ---------------------------------------------------------------------
-- 5. Games and their pricing, now editable from the admin panel
-- ---------------------------------------------------------------------
-- Games, durations and prices used to be PHP arrays inside
-- Controllers/Keys.php: adding a game or changing a price meant editing
-- code and re-uploading. They live here now, and Admin -> Games edits
-- them.
--
-- Prices are per device, per key. A duration belongs to one game, so
-- "30 days" can cost something different for each title, and a game can
-- offer a tier no other game does.
--
-- games.code is what goes into keys_code.game and into the key string
-- itself (CODM_a1b2c3...). Existing keys reference it, so the panel
-- refuses to change a code once keys exist against it. The display name
-- is free to change at any time.

CREATE TABLE IF NOT EXISTS `games` (
  `id_game`    INT AUTO_INCREMENT PRIMARY KEY,
  `code`       VARCHAR(32)  NOT NULL,
  `name`       VARCHAR(96)  NOT NULL,
  `status`     TINYINT      NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  UNIQUE KEY `uniq_games_code` (`code`),
  KEY `idx_games_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `game_durations` (
  `id_duration` INT AUTO_INCREMENT PRIMARY KEY,
  `game_id`     INT           NOT NULL,
  `hours`       INT           NOT NULL,
  `label`       VARCHAR(64)   NOT NULL,
  `price`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `admin_only`  TINYINT       NOT NULL DEFAULT 0,
  `status`      TINYINT       NOT NULL DEFAULT 1,
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  UNIQUE KEY `uniq_game_hours` (`game_id`, `hours`),
  KEY `idx_dur_game` (`game_id`, `status`, `sort_order`),
  CONSTRAINT `fk_dur_game` FOREIGN KEY (`game_id`)
      REFERENCES `games` (`id_game`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The three games and four tiers that were hard-coded, so nothing
-- changes on the day you deploy. INSERT IGNORE means re-running this
-- file will not duplicate them or undo your later edits.
INSERT IGNORE INTO `games` (`code`, `name`, `status`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('CODM',  'Call of Duty Mobile', 1, 10, NOW(), NOW()),
  ('PUBGM', 'PUBG Mobile',         1, 20, NOW(), NOW()),
  ('FF',    'Free Fire Mobile',    1, 30, NOW(), NOW());

-- admin_only = 1 on the 1-hour tier: it is the free test key, and a
-- reseller must never be able to buy it. The old code enforced that with
-- two separate PHP arrays; it is one flag now.
INSERT IGNORE INTO `game_durations`
  (`game_id`, `hours`, `label`, `price`, `admin_only`, `status`, `sort_order`, `created_at`, `updated_at`)
SELECT g.`id_game`, d.`hours`, d.`label`, d.`price`, d.`admin_only`, 1, d.`sort_order`, NOW(), NOW()
FROM `games` g
CROSS JOIN (
      SELECT    1 AS hours, '1 Hour (test key)' AS label,  0.00 AS price, 1 AS admin_only, 10 AS sort_order
UNION SELECT   24,          '1 Day',                       1.00,          0,               20
UNION SELECT  168,          '7 Days',                      5.00,          0,               30
UNION SELECT  720,          '30 Days',                    12.00,          0,               40
) d
WHERE g.`code` IN ('CODM', 'PUBGM', 'FF');

-- Check:
--     SELECT g.code, d.hours, d.label, d.price, d.admin_only
--       FROM games g JOIN game_durations d ON d.game_id = g.id_game
--      ORDER BY g.sort_order, d.sort_order;
