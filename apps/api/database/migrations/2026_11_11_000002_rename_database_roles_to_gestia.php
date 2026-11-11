<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kit v1.13 · ADR 0014 (GestiaHR): los roles de base se llaman gestia_app y gestia_platform.
 * En bases creadas con el esquema anterior se renombran; conservan permisos y políticas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ayni_app') AND NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                ALTER ROLE ayni_app RENAME TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ayni_platform') AND NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                ALTER ROLE ayni_platform RENAME TO gestia_platform;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void {}
};
