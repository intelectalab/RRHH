<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WFL-8 · Una tarea de RRHH puede asignarse a una persona o a un rol.
 *
 * database/schema.sql ya trae la columna para instalaciones nuevas; esta migración
 * la agrega en bases existentes (idempotente) con clave foránea compuesta por organización.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE hr_tasks ADD COLUMN IF NOT EXISTS assignee_role_id uuid');
        DB::statement(<<<'SQL'
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hr_tasks_assignee_role_id') THEN
                ALTER TABLE hr_tasks ADD CONSTRAINT fk_hr_tasks_assignee_role_id
                  FOREIGN KEY (tenant_id, assignee_role_id) REFERENCES roles (tenant_id, id);
              END IF;
            END $$
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS ix_hr_tasks_assignee_role_id ON hr_tasks (tenant_id, assignee_role_id)');
    }

    public function down(): void
    {
        // Historia de trabajo: no se elimina la columna (regla 2).
    }
};
