<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WEB-2 · Marca de módulo a la venta: el sitio gestiahr.com muestra «Disponible» o «Próximamente» según
 * modules.is_published, que cambia la consola. Al primer corte comercial están a la venta base, core y time_off;
 * los demás se publican desde la consola cuando estén listos.
 *
 * database/schema.sql lo trae para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $existed = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'modules' AND column_name = 'is_published') AS e")?->e;
        DB::unprepared(<<<'SQL'
            ALTER TABLE modules ADD COLUMN IF NOT EXISTS is_published boolean NOT NULL DEFAULT false;
            ALTER TABLE modules ADD COLUMN IF NOT EXISTS published_at timestamptz;
            SQL);
        if (! $existed) {
            DB::update("UPDATE modules SET is_published = true, published_at = now() WHERE code IN ('base', 'core', 'time_off')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared('ALTER TABLE modules DROP COLUMN IF EXISTS published_at; ALTER TABLE modules DROP COLUMN IF EXISTS is_published;');
    }
};
