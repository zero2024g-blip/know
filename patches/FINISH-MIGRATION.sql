-- =====================================================================
--  FINISH-MIGRATION.sql
--  Run this AFTER the "#1060 Duplicate column 'max_devices'" error.
--
--  That error only means your games table already had max_devices, so the
--  combined ALTER stopped and everything after it did not run. This script
--  adds every remaining column/table, but ONLY IF IT IS MISSING, so it is
--  100% safe to run now, and safe to run again if you are unsure. Nothing
--  here drops or overwrites a key.
--
--  Import it in phpMyAdmin exactly like the last one. It should finish with
--  no error. (Requires MariaDB, which you have.)
-- =====================================================================

-- 10a. Per-game device controls (adds only what is missing) --------------
ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `max_devices`     INT           NOT NULL DEFAULT 1    AFTER `name`;
ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `allow_unlimited` TINYINT       NOT NULL DEFAULT 0    AFTER `max_devices`;
ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `device_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `allow_unlimited`;
ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `device_mode`     TINYINT       NOT NULL DEFAULT 2    AFTER `device_price`;

-- 10a-2. Per-game quantity controls --------------------------------------
ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `max_qty`  INT     NOT NULL DEFAULT 0 AFTER `device_mode`;
ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `qty_mode` TINYINT NOT NULL DEFAULT 2 AFTER `max_qty`;

-- 10a-3. Per-key "may be unlimited" right --------------------------------
ALTER TABLE `keys_code` ADD COLUMN IF NOT EXISTS `unlimited_ok` TINYINT NOT NULL DEFAULT 0 AFTER `max_devices`;
UPDATE `keys_code` SET `unlimited_ok` = 1 WHERE `max_devices` <= 0;

-- 10b. Own a key by the seller's immutable id ----------------------------
ALTER TABLE `keys_code` ADD COLUMN IF NOT EXISTS `registrator_id` INT NULL AFTER `registrator`;
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_reg_id` (`registrator_id`);
UPDATE `keys_code` k
  JOIN `users` u ON u.username = k.registrator
  SET k.registrator_id = u.id_users
  WHERE k.registrator_id IS NULL;

-- 11a. Readable sign-in IP -----------------------------------------------
ALTER TABLE `login_sessions` ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) NULL AFTER `ip_hash`;

-- 11b. Security log table (created only if missing) ----------------------
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

-- =====================================================================
--  VERIFY (optional). Run these and check the new columns are present:
--     SHOW COLUMNS FROM `games`;        -- expect max_devices, allow_unlimited,
--                                          device_price, device_mode, max_qty, qty_mode
--     SHOW COLUMNS FROM `keys_code`;    -- expect unlimited_ok, registrator_id
--     SHOW COLUMNS FROM `login_sessions`; -- expect ip_address
--     SHOW TABLES LIKE 'security_log';  -- expect one row
-- =====================================================================
