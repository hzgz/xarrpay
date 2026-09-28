CREATE TABLE IF NOT EXISTS "plugin_runtime_state" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "account_id" INTEGER NOT NULL UNIQUE,
  "plugin_name" TEXT NOT NULL DEFAULT '',
  "online" INTEGER NOT NULL DEFAULT 0,
  "last_heartbeat_at" INTEGER NOT NULL DEFAULT 0,
  "last_cron_at" INTEGER NOT NULL DEFAULT 0,
  "last_flow_at" INTEGER NOT NULL DEFAULT 0,
  "last_flow_count" INTEGER NOT NULL DEFAULT 0,
  "last_error" TEXT NOT NULL DEFAULT '',
  "worker_id" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL DEFAULT 0,
  "updated_at" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_plugin_runtime_state_plugin"
  ON "plugin_runtime_state" ("plugin_name", "online", "updated_at");

CREATE TABLE IF NOT EXISTS "plugin_runtime_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "account_id" INTEGER NOT NULL,
  "plugin_name" TEXT NOT NULL DEFAULT '',
  "event" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 0,
  "message" TEXT NOT NULL DEFAULT '',
  "flow_count" INTEGER NOT NULL DEFAULT 0,
  "worker_id" TEXT NOT NULL DEFAULT '',
  "started_at" INTEGER NOT NULL DEFAULT 0,
  "finished_at" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_plugin_runtime_log_account"
  ON "plugin_runtime_log" ("account_id", "id");

CREATE TABLE IF NOT EXISTS "third_order" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "plugin_name" TEXT NOT NULL DEFAULT '',
  "account_id" INTEGER NOT NULL,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "third_order_id" TEXT NOT NULL,
  "amount" INTEGER NOT NULL DEFAULT 0,
  "trans_time" INTEGER NOT NULL DEFAULT 0,
  "remark" TEXT NOT NULL DEFAULT '',
  "buyer_name" TEXT NOT NULL DEFAULT '',
  "raw_data" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 0,
  "matched_order_id" INTEGER NOT NULL DEFAULT 0,
  "error_message" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL DEFAULT 0,
  "updated_at" INTEGER NOT NULL DEFAULT 0,
  UNIQUE ("plugin_name", "account_id", "third_order_id")
);
CREATE INDEX IF NOT EXISTS "idx_third_order_match"
  ON "third_order" ("account_id", "status", "amount", "trans_time");
CREATE INDEX IF NOT EXISTS "idx_third_order_order"
  ON "third_order" ("matched_order_id", "created_at");
