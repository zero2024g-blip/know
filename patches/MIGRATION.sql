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
-- 1. Three new tables: the rate limiters
-- ---------------------------------------------------------------------
-- These did not exist before. WITHOUT THEM THE PANEL STILL RUNS, and
-- that is the danger: every rate-limit query fails, the failure is
-- caught so the page keeps working, and login has no brute-force limit
-- at all while looking perfectly healthy. The code now logs this at
-- 'critical', but creating the tables is the actual fix.
--
--   auth_ratelimit    - failed logins and registrations, per IP
--   check_ratelimit   - failed public key checks, per IP
--   connect_ratelimit - failed lookups from the app connector, per IP
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

-- The connector's own limiter. Same shape, separate table on purpose: a
-- burst of bad key lookups from one app must not lock the panel's login
-- out for everyone behind the same carrier NAT, and the two want very
-- different thresholds.

CREATE TABLE IF NOT EXISTS `connect_ratelimit` (
  `ip_hash`       CHAR(32) NOT NULL,
  `fails`         INT NOT NULL DEFAULT 0,
  `window_end`    INT NOT NULL DEFAULT 0,
  `blocked_until` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip_hash`),
  KEY `idx_connect_rl_stale` (`blocked_until`, `window_end`)
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

-- ---------------------------------------------------------------------
-- 6. Balance ledger — who was charged or credited, when, and by whom
-- ---------------------------------------------------------------------
-- Nothing recorded balance movements before this. `users.saldo` held a
-- number and that was all: a seller could not see what they had spent,
-- and no one could answer "who topped this account up, and when".
--
-- Every change to a balance writes a row here from now on:
--   topup / adjustment  an admin changed the balance by hand
--   key_purchase        keys were generated and paid for
--   referral_signup     the opening credit from a referral code
--
-- `delta` is signed, `balance_after` is the balance once it was applied,
-- and `actor` is the username who caused it. Rows are never edited or
-- deleted — the point of a ledger is that it only grows.
--
-- Movements from before this table existed cannot be reconstructed, so
-- history starts on the day you run this.

CREATE TABLE IF NOT EXISTS `balance_log` (
  `id_log`        INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT           NOT NULL,
  `delta`         DECIMAL(14,2) NOT NULL,
  `balance_after` DECIMAL(14,2) NOT NULL,
  `reason`        VARCHAR(32)   NOT NULL,
  `actor`         VARCHAR(64)   NULL,
  `note`          VARCHAR(255)  NULL,
  `created_at`    DATETIME      NULL,
  `updated_at`    DATETIME      NULL,
  KEY `idx_bl_user` (`user_id`, `id_log`),
  KEY `idx_bl_reason` (`user_id`, `reason`),
  CONSTRAINT `fk_bl_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`id_users`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An opening row per account, so a balance that already exists is
-- explained rather than appearing from nowhere in the ledger. It is
-- marked `opening` precisely because its real origin is unknown.
INSERT INTO `balance_log` (`user_id`, `delta`, `balance_after`, `reason`, `actor`, `note`, `created_at`, `updated_at`)
SELECT u.`id_users`, u.`saldo`, u.`saldo`, 'opening', NULL,
       'Balance as it stood when the ledger was created', NOW(), NOW()
  FROM `users` u
 WHERE NOT EXISTS (SELECT 1 FROM `balance_log` b WHERE b.`user_id` = u.`id_users`);

-- Check:
--     SELECT u.username, b.reason, b.delta, b.balance_after, b.actor, b.created_at
--       FROM balance_log b JOIN users u ON u.id_users = b.user_id
--      ORDER BY b.id_log DESC LIMIT 20;

-- ---------------------------------------------------------------------
-- 7. Sign-in history, and the session binding that makes it useful
-- ---------------------------------------------------------------------
-- One row per sign-in: when it started, from what device, and when it
-- ended. A seller sees their own; an admin sees everyone's.
--
-- `session_id` is a hash of the CodeIgniter session id, never the id
-- itself — a leaked backup of this table must not hand anyone a working
-- session.
--
-- `fingerprint` is the anti-theft part. It is a hash of the browser's
-- user agent plus a secret, checked on every request. A stolen session
-- cookie replayed from a different browser produces a different
-- fingerprint and the session is destroyed. See DEPLOY.md.

CREATE TABLE IF NOT EXISTS `login_sessions` (
  `id_session`   INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT          NOT NULL,
  `username`     VARCHAR(66)  NOT NULL,
  `session_id`   CHAR(64)     NOT NULL,
  `fingerprint`  CHAR(64)     NOT NULL,
  `ip_hash`      CHAR(32)     NULL,
  `user_agent`   VARCHAR(255) NULL,
  `device`       VARCHAR(96)  NULL,
  `login_at`     DATETIME     NOT NULL,
  `last_seen_at` DATETIME     NULL,
  `logout_at`    DATETIME     NULL,
  `end_reason`   VARCHAR(24)  NULL,
  `created_at`   DATETIME NULL,
  `updated_at`   DATETIME NULL,
  UNIQUE KEY `uniq_ls_session` (`session_id`),
  KEY `idx_ls_user` (`user_id`, `id_session`),
  KEY `idx_ls_open` (`user_id`, `logout_at`),
  CONSTRAINT `fk_ls_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`id_users`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 8. Deleted keys are archived, not erased
-- ---------------------------------------------------------------------
-- An admin can delete a key now, and the row moves here instead of
-- vanishing. The history in `history` stays where it is and still refers
-- to the key by id, so a deletion never leaves a gap in the record of
-- what was sold.
--
-- Deletions are also rate limited per admin (see DEPLOY.md): an account
-- that is taken over cannot wipe the table and walk away.

CREATE TABLE IF NOT EXISTS `keys_deleted` (
  `id_deleted`   INT AUTO_INCREMENT PRIMARY KEY,
  `id_keys`      INT          NOT NULL,
  `game`         VARCHAR(32)  NULL,
  `user_key`     VARCHAR(32)  NULL,
  `duration`     INT          NULL,
  `expired_date` DATETIME     NULL,
  `max_devices`  INT          NULL,
  `devices`      MEDIUMTEXT   NULL,
  `status`       TINYINT      NULL,
  `registrator`  VARCHAR(32)  NULL,
  `key_created`  DATETIME     NULL,
  `deleted_by`   VARCHAR(66)  NOT NULL,
  `reason`       VARCHAR(255) NULL,
  `created_at`   DATETIME NULL,
  `updated_at`   DATETIME NULL,
  KEY `idx_kd_key`   (`id_keys`),
  KEY `idx_kd_who`   (`deleted_by`, `id_deleted`),
  KEY `idx_kd_when`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 9. Panel settings
-- ---------------------------------------------------------------------
-- One row per setting, so an admin can change behaviour from the panel
-- instead of editing a file. Today it holds the maintenance switch for
-- the app connector:
--
--   connector.maintenance          '1' = refuse every key check
--   connector.maintenance_message  what the app is told instead
--
-- Reading it FAILS OPEN. If this table is missing or unreachable the
-- apps keep working, because a database hiccup must not look like a
-- deliberate shutdown. The failure is logged at 'critical'.

CREATE TABLE IF NOT EXISTS `settings` (
  `name`       VARCHAR(64) NOT NULL,
  `value`      TEXT        NULL,
  `updated_by` VARCHAR(66) NULL,
  `created_at` DATETIME    NULL,
  `updated_at` DATETIME    NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Off to begin with. INSERT IGNORE so re-running never switches a live
-- panel back on or off under you.
INSERT IGNORE INTO `settings` (`name`, `value`, `created_at`, `updated_at`)
VALUES ('connector.maintenance', '0', NOW(), NOW());


-- Check:
--     SELECT username, device, login_at, logout_at, end_reason
--       FROM login_sessions ORDER BY id_session DESC LIMIT 10;
--     SELECT user_key, deleted_by, created_at FROM keys_deleted ORDER BY id_deleted DESC;
--     SELECT name, value, updated_by, updated_at FROM settings;


-- ---------------------------------------------------------------------
-- 10. Per-game device limits, and per-key ownership by id
--     Added 2026-08-28. Two independent changes; both are safe (they add
--     columns and backfill, they never drop or overwrite a key).
-- ---------------------------------------------------------------------

-- 10a. Device controls per game, all set from Admin -> Games:
--        max_devices     the most devices a SELLER may put on a key: a plain
--                        1..1000 number. An admin is never bound by it.
--        allow_unlimited 1 = a 0 in the Max Devices box at key creation makes
--                        an unlimited key (accepts any number of devices);
--                        0 = a 0 is refused for everyone, admin included,
--                        with "devices must be at least 1". Off by default.
--                        An unlimited key is charged the base tier only.
--        device_price    the charge for each device beyond the first (0 = free).
--        device_mode     who may set the count:
--                          2 = everyone (admin + seller, seller capped)
--                          1 = admins only (a seller's key is 1 device)
--                          0 = off (every key is 1 device)
--      Defaults keep new games to one device, unlimited off, until you change it.
ALTER TABLE `games`
  ADD COLUMN `max_devices`     INT           NOT NULL DEFAULT 1    AFTER `name`,
  ADD COLUMN `allow_unlimited` TINYINT       NOT NULL DEFAULT 0    AFTER `max_devices`,
  ADD COLUMN `device_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `allow_unlimited`,
  ADD COLUMN `device_mode`     TINYINT       NOT NULL DEFAULT 2    AFTER `device_price`;

-- 10b. Own a key by the seller's immutable id, not their username.
--      A username can be deleted and registered again by someone else; the
--      id never is. Without this, a re-registered "AliAli" inherits every key
--      the previous "AliAli" made.
ALTER TABLE `keys_code`
  ADD COLUMN `registrator_id` INT NULL AFTER `registrator`;
ALTER TABLE `keys_code`
  ADD INDEX `idx_reg_id` (`registrator_id`);

-- Backfill: give every existing key to the CURRENT holder of its username.
-- Safe — no seller loses sight of a key they own.
UPDATE `keys_code` k
  JOIN `users` u ON u.username = k.registrator
  SET k.registrator_id = u.id_users
  WHERE k.registrator_id IS NULL;

-- ONLY if you have deleted a seller and someone re-registered the same name:
-- detach the old holder's keys from the new account. Replace 'aliali' with
-- the reused username and run it once per reused name. It removes from the new
-- account exactly the keys created before that account existed — the previous
-- holder's keys — and leaves them owned by nobody (still visible to an admin
-- in the full key list, where you can delete or reassign them).
--
--   UPDATE `keys_code` k
--     JOIN `users` u ON u.username = k.registrator
--     SET k.registrator_id = NULL
--     WHERE k.registrator = 'aliali' AND k.created_at < u.created_at;
--
-- Check which keys ended up owned by nobody:
--   SELECT registrator, COUNT(*) FROM keys_code WHERE registrator_id IS NULL
--     GROUP BY registrator;

-- ---------------------------------------------------------------------
-- 11. Sign-in IP, and a security log
--     Added 2026-08-28. Both safe: one new column, one new table.
-- ---------------------------------------------------------------------

-- 11a. The readable address on each sign-in, for the list in Your settings.
--      The existing ip_hash still does the rate-limit matching; this is only
--      for display, behind the Hide/Show toggle.
ALTER TABLE `login_sessions`
  ADD COLUMN `ip_address` VARCHAR(45) NULL AFTER `ip_hash`;

-- 11b. Failed and blocked attempts at login, register and key check, shown in
--      Admin -> Security log. Written fail-open, so a failure to log never
--      breaks the thing being logged. Rows older than 90 days are trimmed
--      automatically.
CREATE TABLE IF NOT EXISTS `security_log` (
  `id_log`     INT AUTO_INCREMENT PRIMARY KEY,
  `event`      VARCHAR(24) NOT NULL,
  `scope`      VARCHAR(16) NOT NULL,
  `username`   VARCHAR(66) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `device`     VARCHAR(96) NULL,
  `detail`     VARCHAR(96) NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_event`   (`event`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
