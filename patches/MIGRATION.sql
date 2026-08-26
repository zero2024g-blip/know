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
