CREATE TABLE IF NOT EXISTS "invite_visit" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "uid" INTEGER NOT NULL,
  "visitor_hash" TEXT NOT NULL,
  "visit_day" TEXT NOT NULL,
  "created_at" INTEGER NOT NULL
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

CREATE INDEX IF NOT EXISTS "idx_mcp_key_uid_status"
  ON "mcp_key" ("uid", "status", "id");

ALTER TABLE "user" ADD COLUMN "recommend_uid" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "polling_rule" ADD COLUMN "uid" INTEGER NOT NULL DEFAULT 0;
