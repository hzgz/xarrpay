ALTER TABLE "user" ADD COLUMN "last_login_ip" TEXT NOT NULL DEFAULT '';
ALTER TABLE "user" ADD COLUMN "last_login_time" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "login_log" ADD COLUMN "login_type" INTEGER NOT NULL DEFAULT 1;

UPDATE "user"
SET
  "last_login_ip" = COALESCE((
    SELECT "ip" FROM "login_log"
    WHERE "login_log"."uid" = "user"."id" AND "login_log"."status" = 1
    ORDER BY "login_log"."id" DESC LIMIT 1
  ), ''),
  "last_login_time" = COALESCE((
    SELECT "created_at" FROM "login_log"
    WHERE "login_log"."uid" = "user"."id" AND "login_log"."status" = 1
    ORDER BY "login_log"."id" DESC LIMIT 1
  ), 0)
WHERE EXISTS (
  SELECT 1 FROM "login_log"
  WHERE "login_log"."uid" = "user"."id" AND "login_log"."status" = 1
);
