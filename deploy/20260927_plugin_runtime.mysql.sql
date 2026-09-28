CREATE TABLE IF NOT EXISTS `plugin_runtime_state` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `plugin_name` VARCHAR(128) NOT NULL DEFAULT '',
  `online` TINYINT NOT NULL DEFAULT 0,
  `last_heartbeat_at` BIGINT NOT NULL DEFAULT 0,
  `last_cron_at` BIGINT NOT NULL DEFAULT 0,
  `last_flow_at` BIGINT NOT NULL DEFAULT 0,
  `last_flow_count` INT NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `worker_id` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plugin_runtime_state_account` (`account_id`),
  KEY `idx_plugin_runtime_state_plugin` (`plugin_name`, `online`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plugin_runtime_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `plugin_name` VARCHAR(128) NOT NULL DEFAULT '',
  `event` VARCHAR(64) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 0,
  `message` TEXT NULL,
  `flow_count` INT NOT NULL DEFAULT 0,
  `worker_id` VARCHAR(128) NOT NULL DEFAULT '',
  `started_at` BIGINT NOT NULL DEFAULT 0,
  `finished_at` BIGINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_plugin_runtime_log_account` (`account_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `third_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_name` VARCHAR(128) NOT NULL DEFAULT '',
  `account_id` BIGINT UNSIGNED NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `third_order_id` VARCHAR(255) NOT NULL,
  `amount` BIGINT NOT NULL DEFAULT 0,
  `trans_time` BIGINT NOT NULL DEFAULT 0,
  `remark` TEXT NULL,
  `buyer_name` VARCHAR(255) NOT NULL DEFAULT '',
  `raw_data` LONGTEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,
  `matched_order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_third_order_identity` (`plugin_name`, `account_id`, `third_order_id`),
  KEY `idx_third_order_match` (`account_id`, `status`, `amount`, `trans_time`),
  KEY `idx_third_order_order` (`matched_order_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
