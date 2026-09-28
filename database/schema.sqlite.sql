PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS "staff" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" TEXT NOT NULL UNIQUE,
  "password" TEXT NOT NULL,
  "name" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "super" INTEGER NOT NULL DEFAULT 1,
  "email" TEXT NOT NULL DEFAULT '',
  "phone" TEXT NOT NULL DEFAULT '',
  "avatar" TEXT NOT NULL DEFAULT '',
  "mfa_secret" TEXT,
  "token" TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS "user" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" TEXT NOT NULL UNIQUE,
  "password" TEXT NOT NULL,
  "merchant_name" TEXT NOT NULL,
  "app_secret" TEXT NOT NULL UNIQUE,
  "status" INTEGER NOT NULL DEFAULT 1,
  "balance" INTEGER NOT NULL DEFAULT 0,
  "payed" INTEGER NOT NULL DEFAULT 1,
  "audit" INTEGER NOT NULL DEFAULT 1,
  "rebate_balance" INTEGER NOT NULL DEFAULT 0,
  "token" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL DEFAULT 0,
  "last_login_ip" TEXT NOT NULL DEFAULT '',
  "last_login_time" INTEGER NOT NULL DEFAULT 0,
  "email" TEXT NOT NULL DEFAULT '',
  "phone" TEXT NOT NULL DEFAULT '',
  "avatar" TEXT NOT NULL DEFAULT '',
  "mfa_enabled" INTEGER NOT NULL DEFAULT 0,
  "mfa_secret" TEXT NOT NULL DEFAULT '',
  "recommend_uid" INTEGER NOT NULL DEFAULT 0,
  "settings" TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS "pay_type" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "value" TEXT NOT NULL UNIQUE,
  "code" TEXT NOT NULL DEFAULT '',
  "name" TEXT NOT NULL DEFAULT '',
  "label" TEXT NOT NULL,
  "logo" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS "pay_channel" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT NOT NULL,
  "name" TEXT NOT NULL,
  "type" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "plugin_name" TEXT NOT NULL DEFAULT '',
  "remark" TEXT NOT NULL DEFAULT '',
  "options" TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS "pay_account" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "pay_type" TEXT NOT NULL,
  "channel_code" TEXT NOT NULL DEFAULT '',
  "account" TEXT NOT NULL DEFAULT '',
  "account_type" TEXT NOT NULL DEFAULT '',
  "qrcode_data" TEXT NOT NULL DEFAULT '',
  "qrcode" TEXT NOT NULL DEFAULT '',
  "uri" TEXT NOT NULL DEFAULT '',
  "scheme" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "name" TEXT NOT NULL DEFAULT '',
  "sub_account" TEXT NOT NULL DEFAULT '',
  "bind_client_name" TEXT NOT NULL DEFAULT '',
  "sort" INTEGER NOT NULL DEFAULT 50,
  "remark" TEXT NOT NULL DEFAULT '',
  "min_amount" INTEGER NOT NULL DEFAULT 0,
  "max_amount" INTEGER NOT NULL DEFAULT 0,
  "day_amount_limit" INTEGER NOT NULL DEFAULT 0,
  "code" TEXT NOT NULL DEFAULT '',
  "options" TEXT NOT NULL DEFAULT '{}',
  "bind_pay_type" TEXT NOT NULL DEFAULT '[]',
  "online" INTEGER NOT NULL DEFAULT 0,
  "online_start" INTEGER NOT NULL DEFAULT 0,
  "online_end" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL DEFAULT 0,
  "updated_at" INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS "qrcode_login_ticket" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "ticket_id" TEXT NOT NULL UNIQUE,
  "account_id" INTEGER NOT NULL,
  "uid" INTEGER NOT NULL,
  "plugin_name" TEXT NOT NULL,
  "params" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 1,
  "expires_at" INTEGER NOT NULL,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_qrcode_login_ticket_account" ON "qrcode_login_ticket" ("account_id", "status", "expires_at");

CREATE TABLE IF NOT EXISTS "security_ticket" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "ticket" TEXT NOT NULL UNIQUE,
  "kind" TEXT NOT NULL,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "operation" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "expires_at" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL DEFAULT 0,
  "updated_at" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_security_ticket_lookup"
  ON "security_ticket" ("kind", "uid", "status", "expires_at");

CREATE TABLE IF NOT EXISTS "invite_visit" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "visitor_hash" TEXT NOT NULL,
  "visit_day" TEXT NOT NULL,
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "pay_account_ext" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "account_id" INTEGER NOT NULL UNIQUE,
  "plugin_name" TEXT NOT NULL DEFAULT '',
  "external_id" TEXT NOT NULL DEFAULT '',
  "config" TEXT NOT NULL DEFAULT '{}',
  "last_seen_at" INTEGER NOT NULL DEFAULT 0,
  "last_error" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

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

CREATE TABLE IF NOT EXISTS "pay_codes" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "account_id" INTEGER NOT NULL,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "pay_type" TEXT NOT NULL DEFAULT '',
  "channel_code" TEXT NOT NULL DEFAULT '',
  "code_type" TEXT NOT NULL DEFAULT 'qrcode',
  "content" TEXT NOT NULL DEFAULT '',
  "qrcode_data" TEXT NOT NULL DEFAULT '',
  "amount" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "options" TEXT NOT NULL DEFAULT '{}',
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_pay_codes_account_status" ON "pay_codes" ("account_id", "status", "sort", "id");

CREATE TABLE IF NOT EXISTS "pay_polling" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "pay_type" TEXT NOT NULL DEFAULT '',
  "channel_code" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "pay_polling_account" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "polling_id" INTEGER NOT NULL,
  "account_id" INTEGER NOT NULL,
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL,
  UNIQUE ("polling_id", "account_id")
);

CREATE TABLE IF NOT EXISTS "order" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "order_id" TEXT NOT NULL UNIQUE,
  "out_order_id" TEXT NOT NULL,
  "uid" INTEGER NOT NULL,
  "price" INTEGER NOT NULL DEFAULT 0,
  "amount" INTEGER NOT NULL DEFAULT 0,
  "trade_amount" INTEGER NOT NULL DEFAULT 0,
  "actual_amount" TEXT NOT NULL DEFAULT '',
  "rate_amount" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "subject" TEXT NOT NULL DEFAULT '',
  "expire_time" INTEGER NOT NULL DEFAULT 0,
  "pay_type" TEXT NOT NULL DEFAULT '',
  "channel_code" TEXT NOT NULL DEFAULT '',
  "account_id" INTEGER NOT NULL DEFAULT 0,
  "notify_uri" TEXT NOT NULL DEFAULT '',
  "redirect_uri" TEXT NOT NULL DEFAULT '',
  "param" TEXT NOT NULL DEFAULT '',
  "ip" TEXT NOT NULL DEFAULT '',
  "device" TEXT NOT NULL DEFAULT '',
  "out_pay_order_id" TEXT NOT NULL DEFAULT '',
  "notify_status" INTEGER NOT NULL DEFAULT 0,
  "notify_count" INTEGER NOT NULL DEFAULT 0,
  "notify_time" INTEGER,
  "actual_account" TEXT NOT NULL DEFAULT '',
  "pay_ip" TEXT NOT NULL DEFAULT '',
  "remark" TEXT NOT NULL DEFAULT '',
  "pay_time" INTEGER,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "options" (
  "key" TEXT PRIMARY KEY,
  "value" TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS "notice" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "title" TEXT NOT NULL,
  "content" TEXT NOT NULL DEFAULT '',
  "position" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "balance_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "type" TEXT NOT NULL DEFAULT 'adjust',
  "amount" INTEGER NOT NULL DEFAULT 0,
  "before_balance" INTEGER NOT NULL DEFAULT 0,
  "after_balance" INTEGER NOT NULL DEFAULT 0,
  "remark" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "user_rebate_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "balance" INTEGER NOT NULL DEFAULT 0,
  "arrival_time" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "origin_type" TEXT NOT NULL DEFAULT '',
  "origin_id" TEXT NOT NULL DEFAULT '',
  "from_uid" INTEGER NOT NULL DEFAULT 0,
  "remark" TEXT NOT NULL DEFAULT '',
  "source_key" TEXT NOT NULL UNIQUE,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_user_rebate_log_uid" ON "user_rebate_log" ("uid", "created_at");
CREATE INDEX IF NOT EXISTS "idx_user_rebate_log_origin" ON "user_rebate_log" ("origin_type", "origin_id");

CREATE TABLE IF NOT EXISTS "login_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "username" TEXT NOT NULL DEFAULT '',
  "ip" TEXT NOT NULL DEFAULT '',
  "user_agent" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 0,
  "message" TEXT NOT NULL DEFAULT '',
  "login_type" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "notify_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "order_id" TEXT NOT NULL DEFAULT '',
  "url" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 0,
  "response" TEXT NOT NULL DEFAULT '',
  "message" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "notify_queue" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "order_id" TEXT NOT NULL UNIQUE,
  "url" TEXT NOT NULL DEFAULT '',
  "payload" TEXT NOT NULL DEFAULT '',
  "status" TEXT NOT NULL DEFAULT 'queued',
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "max_attempts" INTEGER NOT NULL DEFAULT 5,
  "available_at" INTEGER NOT NULL DEFAULT 0,
  "leased_until" INTEGER NOT NULL DEFAULT 0,
  "lease_token" TEXT NOT NULL DEFAULT '',
  "last_http_status" INTEGER NOT NULL DEFAULT 0,
  "last_response" TEXT NOT NULL DEFAULT '',
  "last_error" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_notify_queue_claim" ON "notify_queue" ("status", "available_at", "leased_until", "id");
CREATE INDEX IF NOT EXISTS "idx_notify_queue_uid" ON "notify_queue" ("uid", "created_at");

CREATE TABLE IF NOT EXISTS "withdraw_account" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "type" TEXT NOT NULL DEFAULT '',
  "name" TEXT NOT NULL DEFAULT '',
  "account" TEXT NOT NULL DEFAULT '',
  "bank_name" TEXT NOT NULL DEFAULT '',
  "qr_code" TEXT NOT NULL DEFAULT '',
  "remark" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "channel_gateway" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "addr" TEXT NOT NULL,
  "channel_code" TEXT NOT NULL,
  "pay_type" TEXT NOT NULL,
  "status" INTEGER NOT NULL DEFAULT 1,
  "options" TEXT NOT NULL DEFAULT '{}',
  "active_time" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "withdraw_income" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "account_id" INTEGER NOT NULL DEFAULT 0,
  "order_id" TEXT NOT NULL DEFAULT '',
  "amount" INTEGER NOT NULL DEFAULT 0,
  "fee" INTEGER NOT NULL DEFAULT 0,
  "real_amount" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "remark" TEXT NOT NULL DEFAULT '',
  "audit_admin" TEXT NOT NULL DEFAULT '',
  "audit_at" INTEGER,
  "audit_remark" TEXT NOT NULL DEFAULT '',
  "pay_remark" TEXT NOT NULL DEFAULT '',
  "paid_at" INTEGER,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "recharge" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "order_id" TEXT NOT NULL UNIQUE,
  "amount" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "remark" TEXT NOT NULL DEFAULT '',
  "pay_type" TEXT NOT NULL DEFAULT '',
  "payment_order_id" TEXT NOT NULL DEFAULT '',
  "paid_at" INTEGER,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "upload_file" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "scope" TEXT NOT NULL DEFAULT '',
  "path" TEXT NOT NULL DEFAULT '',
  "url" TEXT NOT NULL DEFAULT '',
  "name" TEXT NOT NULL DEFAULT '',
  "mime" TEXT NOT NULL DEFAULT '',
  "size" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "staff_action_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "staff_id" INTEGER NOT NULL DEFAULT 0,
  "username" TEXT NOT NULL DEFAULT '',
  "action" TEXT NOT NULL DEFAULT '',
  "method" TEXT NOT NULL DEFAULT '',
  "path" TEXT NOT NULL DEFAULT '',
  "payload" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "work_order_category" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "work_order" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "category_id" INTEGER NOT NULL DEFAULT 0,
  "title" TEXT NOT NULL DEFAULT '',
  "content" TEXT NOT NULL DEFAULT '',
  "attachments" TEXT NOT NULL DEFAULT '[]',
  "status" INTEGER NOT NULL DEFAULT 1,
  "priority" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL,
  "closed_at" INTEGER
);

CREATE TABLE IF NOT EXISTS "work_order_reply" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "pid" INTEGER NOT NULL,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "content" TEXT NOT NULL DEFAULT '',
  "attachments" TEXT NOT NULL DEFAULT '[]',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "notification" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "title" TEXT NOT NULL DEFAULT '',
  "content" TEXT NOT NULL DEFAULT '',
  "type" TEXT NOT NULL DEFAULT 'system',
  "target" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "notification_template" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL DEFAULT '',
  "code" TEXT NOT NULL UNIQUE,
  "content" TEXT NOT NULL DEFAULT '',
  "channel" TEXT NOT NULL DEFAULT 'system',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "page" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL DEFAULT '',
  "path" TEXT NOT NULL DEFAULT '',
  "title" TEXT NOT NULL DEFAULT '',
  "content" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "polling_rule" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "name" TEXT NOT NULL DEFAULT '',
  "pay_type" TEXT NOT NULL DEFAULT '',
  "channel_code" TEXT NOT NULL DEFAULT '',
  "account_ids" TEXT NOT NULL DEFAULT '[]',
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "storage_channel" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL DEFAULT '',
  "driver" TEXT NOT NULL DEFAULT 'local',
  "options" TEXT NOT NULL DEFAULT '{}',
  "is_default" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "third_account" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL DEFAULT '',
  "provider" TEXT NOT NULL DEFAULT '',
  "account" TEXT NOT NULL DEFAULT '',
  "options" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "third_order_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "provider" TEXT NOT NULL DEFAULT '',
  "order_id" TEXT NOT NULL DEFAULT '',
  "request" TEXT NOT NULL DEFAULT '',
  "response" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "security_event" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "type" TEXT NOT NULL DEFAULT '',
  "ip" TEXT NOT NULL DEFAULT '',
  "message" TEXT NOT NULL DEFAULT '',
  "created_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "security_block" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "value" TEXT NOT NULL UNIQUE,
  "type" TEXT NOT NULL DEFAULT 'ip',
  "reason" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "domain_white" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "domain" TEXT NOT NULL,
  "type" INTEGER NOT NULL DEFAULT 1,
  "username" TEXT NOT NULL DEFAULT '',
  "remark" TEXT NOT NULL DEFAULT '',
  "reason" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "black_data" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "type" INTEGER NOT NULL DEFAULT 1,
  "black_value" TEXT NOT NULL,
  "reason" TEXT NOT NULL DEFAULT '',
  "remark" TEXT NOT NULL DEFAULT '',
  "expire_at" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "meal" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "price" INTEGER NOT NULL DEFAULT 0,
  "days" INTEGER NOT NULL DEFAULT 0,
  "description" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "card_group" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "value_type" INTEGER NOT NULL DEFAULT 1,
  "value" INTEGER NOT NULL DEFAULT 0,
  "meal_id" INTEGER NOT NULL DEFAULT 0,
  "total_limit" INTEGER NOT NULL DEFAULT 0,
  "time_limit" INTEGER NOT NULL DEFAULT 0,
  "day_limit" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "card" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "group_id" INTEGER NOT NULL,
  "secret" TEXT NOT NULL UNIQUE,
  "value_type" INTEGER NOT NULL DEFAULT 1,
  "value" INTEGER NOT NULL DEFAULT 0,
  "meal_id" INTEGER NOT NULL DEFAULT 0,
  "use_uid" INTEGER NOT NULL DEFAULT 0,
  "use_time" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "area" (
  "area_id" INTEGER PRIMARY KEY,
  "parent_id" INTEGER NOT NULL DEFAULT 0,
  "name" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS "proxy_pool" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "provinces" TEXT NOT NULL DEFAULT '',
  "city" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "service_account_pool" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL DEFAULT '',
  "type" INTEGER NOT NULL DEFAULT 1,
  "weight" INTEGER NOT NULL DEFAULT 0,
  "config" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "qrcode_template" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "uri" TEXT NOT NULL DEFAULT '',
  "config" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 1,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "third_connect_chat" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "label" TEXT NOT NULL,
  "value" TEXT NOT NULL,
  "sort" INTEGER NOT NULL DEFAULT 50,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "sms_channel" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "plugin_name" TEXT NOT NULL DEFAULT 'local',
  "options" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS "mcp_key" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "name" TEXT NOT NULL,
  "api_key" TEXT NOT NULL UNIQUE,
  "status" INTEGER NOT NULL DEFAULT 1,
  "allow_create" INTEGER NOT NULL DEFAULT 0,
  "settings" TEXT NOT NULL DEFAULT '{}',
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_mcp_key_uid_status" ON "mcp_key" ("uid", "status", "id");

CREATE TABLE IF NOT EXISTS "passkey_credential" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "credential_id" TEXT NOT NULL UNIQUE,
  "public_key" TEXT NOT NULL,
  "sign_count" INTEGER NOT NULL DEFAULT 0,
    "device_name" TEXT NOT NULL DEFAULT '',
    "aaguid" TEXT NOT NULL DEFAULT '',
    "status" INTEGER NOT NULL DEFAULT 1,
    "last_used_at" INTEGER NOT NULL DEFAULT 0,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_passkey_credential_uid" ON "passkey_credential" ("uid", "id");

CREATE TABLE IF NOT EXISTS "webauthn_challenge" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "ticket" TEXT NOT NULL UNIQUE,
  "uid" INTEGER NOT NULL DEFAULT 0,
  "purpose" TEXT NOT NULL,
  "challenge" TEXT NOT NULL,
  "rp_id" TEXT NOT NULL,
  "origin" TEXT NOT NULL,
  "credential_id" TEXT NOT NULL DEFAULT '',
  "status" INTEGER NOT NULL DEFAULT 1,
  "expires_at" INTEGER NOT NULL,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_webauthn_challenge_lookup" ON "webauthn_challenge" ("ticket", "uid", "purpose", "status", "expires_at");

CREATE TABLE IF NOT EXISTS "merchant_connect" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "provider" TEXT NOT NULL,
  "provider_uid" TEXT NOT NULL,
  "nickname" TEXT NOT NULL DEFAULT '',
  "avatar" TEXT NOT NULL DEFAULT '',
  "access_token" TEXT NOT NULL DEFAULT '',
  "refresh_token" TEXT NOT NULL DEFAULT '',
  "expires_at" INTEGER NOT NULL DEFAULT 0,
  "options" TEXT NOT NULL DEFAULT '{}',
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL,
  UNIQUE ("provider", "provider_uid")
);
CREATE INDEX IF NOT EXISTS "idx_merchant_connect_uid" ON "merchant_connect" ("uid", "provider", "status");

CREATE TABLE IF NOT EXISTS "verification_code" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "purpose" TEXT NOT NULL,
  "target" TEXT NOT NULL,
  "code_hash" TEXT NOT NULL,
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "expires_at" INTEGER NOT NULL,
  "created_at" INTEGER NOT NULL,
  "used_at" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_verification_code_lookup" ON "verification_code" ("uid", "purpose", "target", "status", "expires_at");

CREATE TABLE IF NOT EXISTS "app_login_ticket" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "ticket" TEXT NOT NULL UNIQUE,
  "kind" TEXT NOT NULL DEFAULT 'app_bind',
  "uid" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "options" TEXT NOT NULL DEFAULT '{}',
  "scanned_at" INTEGER NOT NULL DEFAULT 0,
  "confirmed_at" INTEGER NOT NULL DEFAULT 0,
  "expires_at" INTEGER NOT NULL,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_app_login_ticket_lookup" ON "app_login_ticket" ("ticket", "uid", "status", "expires_at");

CREATE TABLE IF NOT EXISTS "merchant_rsa_key" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL UNIQUE,
  "public_key" TEXT NOT NULL,
  "private_ciphertext" TEXT NOT NULL,
  "private_nonce" TEXT NOT NULL,
  "private_tag" TEXT NOT NULL,
  "status" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
