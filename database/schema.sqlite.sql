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
  "token" TEXT NOT NULL DEFAULT ''
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
  "bind_pay_type" TEXT NOT NULL DEFAULT '[]'
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
