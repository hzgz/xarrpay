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
