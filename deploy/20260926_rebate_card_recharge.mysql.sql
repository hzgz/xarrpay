ALTER TABLE `recharge`
  ADD COLUMN `pay_type` VARCHAR(64) NOT NULL DEFAULT '' AFTER `remark`,
  ADD COLUMN `payment_order_id` VARCHAR(128) NOT NULL DEFAULT '' AFTER `pay_type`,
  ADD COLUMN `paid_at` BIGINT NULL AFTER `payment_order_id`;

CREATE TABLE IF NOT EXISTS `user_rebate_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `balance` BIGINT NOT NULL DEFAULT 0,
  `arrival_time` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `origin_type` VARCHAR(64) NOT NULL DEFAULT '',
  `origin_id` VARCHAR(128) NOT NULL DEFAULT '',
  `from_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `source_key` VARCHAR(255) NOT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_rebate_log_source_key` (`source_key`),
  KEY `idx_user_rebate_log_uid` (`uid`, `created_at`),
  KEY `idx_user_rebate_log_origin` (`origin_type`, `origin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
