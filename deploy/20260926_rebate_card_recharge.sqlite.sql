ALTER TABLE recharge ADD COLUMN pay_type TEXT NOT NULL DEFAULT '';
ALTER TABLE recharge ADD COLUMN payment_order_id TEXT NOT NULL DEFAULT '';
ALTER TABLE recharge ADD COLUMN paid_at INTEGER;

CREATE TABLE IF NOT EXISTS user_rebate_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uid INTEGER NOT NULL,
  balance INTEGER NOT NULL DEFAULT 0,
  arrival_time INTEGER NOT NULL DEFAULT 0,
  status INTEGER NOT NULL DEFAULT 1,
  origin_type TEXT NOT NULL DEFAULT '',
  origin_id TEXT NOT NULL DEFAULT '',
  from_uid INTEGER NOT NULL DEFAULT 0,
  remark TEXT NOT NULL DEFAULT '',
  source_key TEXT NOT NULL UNIQUE,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_user_rebate_log_uid ON user_rebate_log (uid, created_at);
CREATE INDEX IF NOT EXISTS idx_user_rebate_log_origin ON user_rebate_log (origin_type, origin_id);
