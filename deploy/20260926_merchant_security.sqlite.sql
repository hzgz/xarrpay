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
