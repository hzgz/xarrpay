ALTER TABLE "pay_account" ADD COLUMN "online" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "pay_account" ADD COLUMN "online_start" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "pay_account" ADD COLUMN "online_end" INTEGER NOT NULL DEFAULT 0;
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
