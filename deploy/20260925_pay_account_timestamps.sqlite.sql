-- 适用对象：已经存在 pay_account、但尚未包含时间字段的旧 SQLite 测试库。
-- 执行前提：数据库表结构中不存在下面两个字段；重复执行应当失败，避免误把结构升级当成运行时兼容。
ALTER TABLE "pay_account" ADD COLUMN "created_at" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "pay_account" ADD COLUMN "updated_at" INTEGER NOT NULL DEFAULT 0;
