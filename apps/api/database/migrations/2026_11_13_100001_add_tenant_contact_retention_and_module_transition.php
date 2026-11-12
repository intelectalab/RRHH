<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAS-2 · SAS-5 · Datos de contacto y retención tras la baja de la organización, y fin del periodo de
 * solo lectura de un módulo retirado (RN-SAS-06). database/schema.sql los trae para instalaciones
 * nuevas; aquí se agregan en bases existentes. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS contact_name text;
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS contact_email text;
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS contact_phone text;
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS cancelled_at timestamptz;
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS retention_until date;
            ALTER TABLE tenant_modules ADD COLUMN IF NOT EXISTS read_only_until timestamptz;
        SQL);
    }

    public function down(): void
    {
        // Sin reversión: las columnas guardan historia de la organización.
    }
};
