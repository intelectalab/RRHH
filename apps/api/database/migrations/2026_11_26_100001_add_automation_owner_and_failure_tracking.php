<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WFL-6 · WFL-7 · Responsable de cada regla y marca «con errores» (fallas seguidas que avisan una vez al
 * responsable). Los flujos del constructor visual usan las tablas de definiciones y versiones existentes.
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

        DB::unprepared(<<<'SQL'
            ALTER TABLE automation_rules ADD COLUMN IF NOT EXISTS owner_user_id uuid;
            ALTER TABLE automation_rules ADD COLUMN IF NOT EXISTS failing_since timestamptz;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_automation_rules_owner_user_id') THEN
                ALTER TABLE automation_rules ADD CONSTRAINT fk_automation_rules_owner_user_id
                  FOREIGN KEY (tenant_id, owner_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_automation_rules_owner_user_id ON automation_rules (tenant_id, owner_user_id);
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('automatizaciones.errores_seguidos', 'base', 'Fallas seguidas para avisar', 'WFL-7 · Ejecuciones fallidas seguidas de una regla a partir de las cuales se marca «con errores» y se avisa una vez a su responsable.', 'integer', '3'::jsonb, ARRAY['organization'], '{"min": 1, "max": 50}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        // Ayuda de la pantalla del constructor (solo crea lo que falta).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
