SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `staff` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password` CHAR(32) NOT NULL,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `super` TINYINT NOT NULL DEFAULT 1,
  `email` VARCHAR(255) NOT NULL DEFAULT '',
  `phone` VARCHAR(32) NOT NULL DEFAULT '',
  `avatar` VARCHAR(512) NOT NULL DEFAULT '',
  `mfa_secret` VARCHAR(255) NULL,
  `token` VARCHAR(128) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password` CHAR(32) NOT NULL,
  `merchant_name` VARCHAR(128) NOT NULL,
  `app_secret` VARCHAR(255) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `balance` BIGINT NOT NULL DEFAULT 0,
  `payed` TINYINT NOT NULL DEFAULT 1,
  `audit` TINYINT NOT NULL DEFAULT 1,
  `rebate_balance` BIGINT NOT NULL DEFAULT 0,
  `token` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `last_login_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `last_login_time` BIGINT NOT NULL DEFAULT 0,
  `email` VARCHAR(255) NOT NULL DEFAULT '',
  `phone` VARCHAR(32) NOT NULL DEFAULT '',
  `avatar` VARCHAR(512) NOT NULL DEFAULT '',
  `mfa_enabled` TINYINT NOT NULL DEFAULT 0,
  `mfa_secret` VARCHAR(255) NOT NULL DEFAULT '',
  `recommend_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `settings` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_app_secret` (`app_secret`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `user` AUTO_INCREMENT = 10000;

CREATE TABLE IF NOT EXISTS `pay_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `value` VARCHAR(64) NOT NULL,
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `label` VARCHAR(128) NOT NULL,
  `logo` VARCHAR(512) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_type_value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pay_channel` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `type` VARCHAR(64) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `plugin_name` VARCHAR(128) NOT NULL DEFAULT '',
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `options` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pay_channel_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pay_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `pay_type` VARCHAR(64) NOT NULL,
  `channel_code` VARCHAR(64) NOT NULL DEFAULT '',
  `account` VARCHAR(255) NOT NULL DEFAULT '',
  `account_type` VARCHAR(128) NOT NULL DEFAULT '',
  `qrcode_data` TEXT NULL,
  `qrcode` VARCHAR(512) NOT NULL DEFAULT '',
  `uri` VARCHAR(1024) NOT NULL DEFAULT '',
  `scheme` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `sub_account` VARCHAR(255) NOT NULL DEFAULT '',
  `bind_client_name` VARCHAR(128) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 50,
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `min_amount` BIGINT NOT NULL DEFAULT 0,
  `max_amount` BIGINT NOT NULL DEFAULT 0,
  `day_amount_limit` BIGINT NOT NULL DEFAULT 0,
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `options` TEXT NULL,
  `bind_pay_type` TEXT NULL,
  `online` TINYINT NOT NULL DEFAULT 0,
  `online_start` BIGINT NOT NULL DEFAULT 0,
  `online_end` BIGINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pay_account_uid` (`uid`),
  KEY `idx_pay_account_type` (`pay_type`, `channel_code`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `security_ticket` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket` CHAR(64) NOT NULL,
  `kind` VARCHAR(32) NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `operation` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` BIGINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_security_ticket_ticket` (`ticket`),
  KEY `idx_security_ticket_lookup` (`kind`, `uid`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invite_visit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL,
  `visit_day` DATE NOT NULL,
  `created_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invite_visit_uid_day` (`uid`, `visit_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pay_account_ext` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `plugin_name` VARCHAR(128) NOT NULL DEFAULT '',
  `external_id` VARCHAR(255) NOT NULL DEFAULT '',
  `config` TEXT NULL,
  `last_seen_at` BIGINT NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_account_ext_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `pay_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `pay_type` VARCHAR(64) NOT NULL DEFAULT '',
  `channel_code` VARCHAR(64) NOT NULL DEFAULT '',
  `code_type` VARCHAR(64) NOT NULL DEFAULT 'qrcode',
  `content` TEXT NULL,
  `qrcode_data` LONGTEXT NULL,
  `amount` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `options` TEXT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pay_codes_account_status` (`account_id`, `status`, `sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pay_polling` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `pay_type` VARCHAR(64) NOT NULL DEFAULT '',
  `channel_code` VARCHAR(64) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pay_polling_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `polling_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_polling_account` (`polling_id`, `account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` VARCHAR(128) NOT NULL,
  `out_order_id` VARCHAR(128) NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL,
  `price` BIGINT NOT NULL DEFAULT 0,
  `amount` BIGINT NOT NULL DEFAULT 0,
  `trade_amount` BIGINT NOT NULL DEFAULT 0,
  `actual_amount` VARCHAR(64) NOT NULL DEFAULT '',
  `rate_amount` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `expire_time` BIGINT NOT NULL DEFAULT 0,
  `pay_type` VARCHAR(64) NOT NULL DEFAULT '',
  `channel_code` VARCHAR(64) NOT NULL DEFAULT '',
  `account_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `notify_uri` VARCHAR(1024) NOT NULL DEFAULT '',
  `redirect_uri` VARCHAR(1024) NOT NULL DEFAULT '',
  `param` TEXT NULL,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `device` VARCHAR(255) NOT NULL DEFAULT '',
  `out_pay_order_id` VARCHAR(128) NOT NULL DEFAULT '',
  `notify_status` TINYINT NOT NULL DEFAULT 0,
  `notify_count` INT NOT NULL DEFAULT 0,
  `notify_time` BIGINT NULL,
  `actual_account` VARCHAR(255) NOT NULL DEFAULT '',
  `pay_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `pay_time` BIGINT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_order_id` (`order_id`),
  KEY `idx_order_uid` (`uid`),
  KEY `idx_order_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `options` (
  `key` VARCHAR(128) NOT NULL,
  `value` TEXT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `balance_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(64) NOT NULL DEFAULT 'adjust',
  `amount` BIGINT NOT NULL DEFAULT 0,
  `before_balance` BIGINT NOT NULL DEFAULT 0,
  `after_balance` BIGINT NOT NULL DEFAULT 0,
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_balance_log_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `login_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(1024) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 0,
  `message` VARCHAR(512) NOT NULL DEFAULT '',
  `login_type` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_login_log_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notify_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` VARCHAR(128) NOT NULL DEFAULT '',
  `url` VARCHAR(1024) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 0,
  `response` TEXT NULL,
  `message` VARCHAR(512) NOT NULL DEFAULT '',
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_notify_log_uid` (`uid`, `created_at`),
  KEY `idx_notify_log_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notify_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `order_id` VARCHAR(128) NOT NULL,
  `url` VARCHAR(1024) NOT NULL DEFAULT '',
  `payload` TEXT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
  `available_at` BIGINT NOT NULL DEFAULT 0,
  `leased_until` BIGINT NOT NULL DEFAULT 0,
  `lease_token` CHAR(32) NOT NULL DEFAULT '',
  `last_http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_response` TEXT NULL,
  `last_error` TEXT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notify_queue_order` (`order_id`),
  KEY `idx_notify_queue_claim` (`status`, `available_at`, `leased_until`, `id`),
  KEY `idx_notify_queue_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `withdraw_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(64) NOT NULL DEFAULT '',
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `account` VARCHAR(255) NOT NULL DEFAULT '',
  `bank_name` VARCHAR(128) NOT NULL DEFAULT '',
  `qr_code` VARCHAR(1024) NOT NULL DEFAULT '',
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_withdraw_account_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channel_gateway` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `addr` VARCHAR(1024) NOT NULL,
  `channel_code` VARCHAR(64) NOT NULL,
  `pay_type` VARCHAR(64) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `options` TEXT NULL,
  `active_time` BIGINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_channel_gateway_status` (`channel_code`, `pay_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `withdraw_income` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` VARCHAR(128) NOT NULL DEFAULT '',
  `amount` BIGINT NOT NULL DEFAULT 0,
  `fee` BIGINT NOT NULL DEFAULT 0,
  `real_amount` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `audit_admin` VARCHAR(64) NOT NULL DEFAULT '',
  `audit_at` BIGINT NULL,
  `audit_remark` VARCHAR(512) NOT NULL DEFAULT '',
  `pay_remark` VARCHAR(512) NOT NULL DEFAULT '',
  `paid_at` BIGINT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_withdraw_income_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recharge` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `order_id` VARCHAR(128) NOT NULL,
  `amount` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `pay_type` VARCHAR(64) NOT NULL DEFAULT '',
  `payment_order_id` VARCHAR(128) NOT NULL DEFAULT '',
  `paid_at` BIGINT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recharge_order_id` (`order_id`),
  KEY `idx_recharge_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `upload_file` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `scope` VARCHAR(64) NOT NULL DEFAULT '',
  `path` VARCHAR(1024) NOT NULL DEFAULT '',
  `url` VARCHAR(1024) NOT NULL DEFAULT '',
  `name` VARCHAR(255) NOT NULL DEFAULT '',
  `mime` VARCHAR(128) NOT NULL DEFAULT '',
  `size` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_upload_file_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_action_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `action` VARCHAR(128) NOT NULL DEFAULT '',
  `method` VARCHAR(16) NOT NULL DEFAULT '',
  `path` VARCHAR(1024) NOT NULL DEFAULT '',
  `payload` TEXT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_staff_action_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_order_category` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `category_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `content` TEXT NULL,
  `attachments` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `priority` INT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  `closed_at` BIGINT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_work_order_uid` (`uid`, `created_at`),
  KEY `idx_work_order_category` (`category_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_order_reply` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pid` BIGINT UNSIGNED NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content` TEXT NULL,
  `attachments` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_work_order_reply_pid` (`pid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `content` TEXT NULL,
  `type` VARCHAR(64) NOT NULL DEFAULT 'system',
  `target` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `code` VARCHAR(128) NOT NULL,
  `content` TEXT NULL,
  `channel` VARCHAR(64) NOT NULL DEFAULT 'system',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_template_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `page` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `path` VARCHAR(255) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `content` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_page_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `polling_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `pay_type` VARCHAR(64) NOT NULL DEFAULT '',
  `channel_code` VARCHAR(64) NOT NULL DEFAULT '',
  `account_ids` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `storage_channel` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `driver` VARCHAR(64) NOT NULL DEFAULT 'local',
  `options` TEXT NULL,
  `is_default` TINYINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `third_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `provider` VARCHAR(64) NOT NULL DEFAULT '',
  `account` VARCHAR(255) NOT NULL DEFAULT '',
  `options` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `third_order_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(64) NOT NULL DEFAULT '',
  `order_id` VARCHAR(128) NOT NULL DEFAULT '',
  `request` TEXT NULL,
  `response` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_third_order_log_order` (`order_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `type` VARCHAR(64) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `message` VARCHAR(512) NOT NULL DEFAULT '',
  `created_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_security_event_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_block` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `value` VARCHAR(255) NOT NULL,
  `type` VARCHAR(64) NOT NULL DEFAULT 'ip',
  `reason` VARCHAR(512) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_security_block_value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `domain_white` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain` VARCHAR(255) NOT NULL,
  `type` INT NOT NULL DEFAULT 1,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `reason` VARCHAR(512) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_domain_white_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `black_data` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` INT NOT NULL DEFAULT 1,
  `black_value` VARCHAR(255) NOT NULL,
  `reason` VARCHAR(512) NOT NULL DEFAULT '',
  `remark` VARCHAR(512) NOT NULL DEFAULT '',
  `expire_at` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_black_data_value` (`black_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meal` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `price` BIGINT NOT NULL DEFAULT 0,
  `days` INT NOT NULL DEFAULT 0,
  `description` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `value_type` INT NOT NULL DEFAULT 1,
  `value` BIGINT NOT NULL DEFAULT 0,
  `meal_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_limit` INT NOT NULL DEFAULT 0,
  `time_limit` INT NOT NULL DEFAULT 0,
  `day_limit` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `secret` VARCHAR(255) NOT NULL,
  `value_type` INT NOT NULL DEFAULT 1,
  `value` BIGINT NOT NULL DEFAULT 0,
  `meal_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `use_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `use_time` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_card_secret` (`secret`),
  KEY `idx_card_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `area` (
  `area_id` BIGINT NOT NULL,
  `parent_id` BIGINT NOT NULL DEFAULT 0,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`area_id`),
  KEY `idx_area_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proxy_pool` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `provinces` VARCHAR(255) NOT NULL DEFAULT '',
  `city` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_account_pool` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `type` INT NOT NULL DEFAULT 1,
  `weight` INT NOT NULL DEFAULT 0,
  `config` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qrcode_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `uri` VARCHAR(1024) NOT NULL DEFAULT '',
  `config` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 50,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `third_connect_chat` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(128) NOT NULL,
  `value` VARCHAR(1024) NOT NULL,
  `sort` INT NOT NULL DEFAULT 50,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_channel` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `plugin_name` VARCHAR(128) NOT NULL DEFAULT 'local',
  `options` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `passkey_credential` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `credential_id` VARCHAR(512) NOT NULL,
  `public_key` TEXT NOT NULL,
  `sign_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `device_name` VARCHAR(255) NOT NULL DEFAULT '',
  `aaguid` VARCHAR(128) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `last_used_at` BIGINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passkey_credential_id` (`credential_id`),
  KEY `idx_passkey_credential_uid` (`uid`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webauthn_challenge` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket` VARCHAR(128) NOT NULL,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `purpose` VARCHAR(64) NOT NULL,
  `challenge` VARCHAR(255) NOT NULL,
  `rp_id` VARCHAR(255) NOT NULL,
  `origin` VARCHAR(512) NOT NULL,
  `credential_id` VARCHAR(512) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `expires_at` BIGINT NOT NULL,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webauthn_challenge_ticket` (`ticket`),
  KEY `idx_webauthn_challenge_lookup` (`ticket`, `uid`, `purpose`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `merchant_connect` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(64) NOT NULL,
  `provider_uid` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(255) NOT NULL DEFAULT '',
  `avatar` VARCHAR(1024) NOT NULL DEFAULT '',
  `access_token` TEXT NULL,
  `refresh_token` TEXT NULL,
  `expires_at` BIGINT NOT NULL DEFAULT 0,
  `options` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchant_connect_provider_uid` (`provider`, `provider_uid`),
  KEY `idx_merchant_connect_uid` (`uid`, `provider`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `verification_code` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(64) NOT NULL,
  `target` VARCHAR(255) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `expires_at` BIGINT NOT NULL,
  `created_at` BIGINT NOT NULL,
  `used_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_verification_code_lookup` (`uid`, `purpose`, `target`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_login_ticket` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket` VARCHAR(128) NOT NULL,
  `kind` VARCHAR(32) NOT NULL DEFAULT 'app_bind',
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `options` TEXT NULL,
  `scanned_at` BIGINT NOT NULL DEFAULT 0,
  `confirmed_at` BIGINT NOT NULL DEFAULT 0,
  `expires_at` BIGINT NOT NULL,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_login_ticket_ticket` (`ticket`),
  KEY `idx_app_login_ticket_lookup` (`ticket`, `uid`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `merchant_rsa_key` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `public_key` TEXT NOT NULL,
  `private_ciphertext` TEXT NOT NULL,
  `private_nonce` VARCHAR(128) NOT NULL,
  `private_tag` VARCHAR(128) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchant_rsa_key_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
