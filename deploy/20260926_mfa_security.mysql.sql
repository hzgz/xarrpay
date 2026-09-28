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
