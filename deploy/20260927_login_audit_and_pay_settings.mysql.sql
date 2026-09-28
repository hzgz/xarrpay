ALTER TABLE `user`
  ADD COLUMN `last_login_ip` VARCHAR(64) NOT NULL DEFAULT '' AFTER `created_at`,
  ADD COLUMN `last_login_time` BIGINT NOT NULL DEFAULT 0 AFTER `last_login_ip`;

ALTER TABLE `login_log`
  ADD COLUMN `login_type` TINYINT NOT NULL DEFAULT 1 AFTER `message`;

UPDATE `user` u
INNER JOIN (
  SELECT l.uid, l.ip, l.created_at
  FROM login_log l
  INNER JOIN (
    SELECT uid, MAX(id) AS max_id
    FROM login_log
    WHERE status = 1
    GROUP BY uid
  ) latest ON latest.max_id = l.id
) latest_login ON latest_login.uid = u.id
SET u.last_login_ip = latest_login.ip,
    u.last_login_time = latest_login.created_at;
