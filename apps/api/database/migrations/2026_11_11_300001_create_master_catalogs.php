<?php

use App\Modules\Settings\Application\MasterCatalogImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CFG-10 · Catálogos maestros (tipos de documento, países, ubigeo, bancos, motivos de fin) y
 * etiquetas por idioma en los elementos de catálogo de la organización (CFG-6). Idempotente: en una
 * base creada con kit/database/schema.sql ya existen tablas y datos y no cambia nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS master_catalogs (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              code text NOT NULL,
              name text NOT NULL,
              description text,
              is_extensible boolean NOT NULL DEFAULT false,
              country_code text,
              source text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_master_catalogs_code UNIQUE (code),
              CONSTRAINT fk_master_catalogs_country_code FOREIGN KEY (country_code) REFERENCES countries (code)
            );
            COMMENT ON TABLE master_catalogs IS 'Catálogos maestros comunes (tipos de documento, países, ubigeo, bancos, motivos de cese). Los mantiene la plataforma sin desplegar; is_extensible permite que cada organización agregue elementos propios en catalogs con el mismo código.';

            CREATE TABLE IF NOT EXISTS master_catalog_items (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              master_catalog_id uuid NOT NULL,
              code text NOT NULL,
              label text NOT NULL,
              labels jsonb,
              statutory_code text,
              parent_code text,
              sort_order integer NOT NULL DEFAULT 0,
              metadata jsonb,
              is_verified boolean NOT NULL DEFAULT false,
              valid_from date NOT NULL,
              valid_to date,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_master_catalog_items_master_catalog_id_code UNIQUE (master_catalog_id, code),
              CONSTRAINT ck_master_catalog_items_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT fk_master_catalog_items_master_catalog_id FOREIGN KEY (master_catalog_id) REFERENCES master_catalogs (id)
            );
            COMMENT ON TABLE master_catalog_items IS 'Elementos de un catálogo maestro con código estable para integraciones y declaraciones (statutory_code), etiquetas por idioma y vigencia. is_verified = falso mientras no se contraste con la fuente oficial.';
            CREATE INDEX IF NOT EXISTS ix_master_catalogs_country_code ON master_catalogs (country_code);

            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_master_catalogs_touch') THEN
                CREATE TRIGGER trg_master_catalogs_touch BEFORE UPDATE ON master_catalogs FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_master_catalog_items_touch') THEN
                CREATE TRIGGER trg_master_catalog_items_touch BEFORE UPDATE ON master_catalog_items FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
            END $$;

            ALTER TABLE catalog_items ADD COLUMN IF NOT EXISTS labels jsonb;

            -- Catálogo de plataforma: la aplicación solo lee; la consola lo mantiene.
            REVOKE ALL ON master_catalogs, master_catalog_items FROM PUBLIC;
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON master_catalogs, master_catalog_items TO gestia_platform;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT ON master_catalogs, master_catalog_items TO gestia_app;
                REVOKE INSERT, UPDATE, DELETE ON master_catalogs, master_catalog_items FROM gestia_app;
              END IF;
            END $$;
            SQL);

        // Datos iniciales: solo si la tabla está vacía (una base del esquema del kit ya los trae).
        $file = MasterCatalogImporter::kitFile();
        if ($file !== null && ! DB::table('master_catalogs')->exists()) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) file_get_contents($file), true) ?: [];
            app(MasterCatalogImporter::class)->importJson($payload);
        }
    }

    public function down(): void {}
};
