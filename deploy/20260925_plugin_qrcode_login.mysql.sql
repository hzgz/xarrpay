ALTER TABLE `pay_account`
  ADD COLUMN `online` TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN `online_start` BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN `online_end` BIGINT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `qrcode_login_ticket` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` VARCHAR(64) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL,
  `plugin_name` VARCHAR(128) NOT NULL,
  `params` TEXT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `expires_at` BIGINT NOT NULL,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qrcode_login_ticket` (`ticket_id`),
  KEY `idx_qrcode_login_ticket_account` (`account_id`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
