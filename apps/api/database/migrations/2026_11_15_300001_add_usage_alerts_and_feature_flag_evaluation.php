<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · SAS-7 · Límites y consumo, banderas de funcionalidad.
 *  - tenant_limits.warned_at / reached_at: el aviso al acercarse o llegar al tope sale una sola vez (RN-SAS-02).
 *  - tenants.beta_groups: grupos beta de la organización para las reglas de banderas.
 *  - feature_flag_rules pasa a catálogo de solo lectura para la aplicación.
 *  - current_feature_flags(): evaluación única (RN-SAS-07) que usan la API y, vía /me, la web.
 *  - Parámetro limites.porcentaje_alerta.
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS beta_groups text[];
            ALTER TABLE tenant_limits ADD COLUMN IF NOT EXISTS warned_at timestamptz;
            ALTER TABLE tenant_limits ADD COLUMN IF NOT EXISTS reached_at timestamptz;
            GRANT SELECT ON feature_flag_rules TO gestia_app;
            REVOKE INSERT, UPDATE, DELETE ON feature_flag_rules FROM gestia_app;
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('limites.porcentaje_alerta', 'base', 'Aviso de consumo del plan (%)', 'Porcentaje de un límite del plan (colaboradores, administradores, empresas, almacenamiento) a partir del cual se avisa a los administradores (RN-SAS-02); al 100 % se bloquean las nuevas altas y subidas.', 'integer', '80'::jsonb, ARRAY['organization'], '{"min": 50, "max": 99}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION current_feature_flags() RETURNS TABLE (code text, enabled boolean, source text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp
AS $$
  WITH t AS (
    SELECT id, country_code, COALESCE(beta_groups, '{}'::text[]) AS beta_groups
      FROM tenants
     WHERE id = NULLIF(current_setting('app.tenant_id', true), '')::uuid AND deleted_at IS NULL
  ), pl AS (
    SELECT p.code FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN t ON t.id = s.tenant_id
     WHERE s.status NOT IN ('cancelled', 'expired')
  ), ev AS (
    SELECT f.code, f.default_enabled,
           (SELECT o.enabled FROM tenant_feature_flags o
             WHERE o.tenant_id = t.id AND o.feature_flag_code = f.code AND (o.expires_at IS NULL OR o.expires_at > now())) AS by_tenant,
           (SELECT bool_or(r.enabled) FROM feature_flag_rules r
             WHERE r.feature_flag_id = f.id AND r.target_kind = 'plan' AND r.target_value IN (SELECT code FROM pl)) AS by_plan,
           (SELECT bool_or(r.enabled) FROM feature_flag_rules r
             WHERE r.feature_flag_id = f.id AND r.target_kind = 'beta_group' AND r.target_value = ANY (t.beta_groups)) AS by_beta,
           (SELECT bool_or(r.enabled) FROM feature_flag_rules r
             WHERE r.feature_flag_id = f.id AND r.target_kind = 'country' AND r.target_value = t.country_code) AS by_country,
           (SELECT bool_or(r.enabled) FROM feature_flag_rules r
             WHERE r.feature_flag_id = f.id AND r.target_kind = 'percentage' AND r.target_value ~ '^[0-9]{1,3}$'
               AND abs(hashtext(f.code || ':' || t.id::text)::bigint) % 100 < r.target_value::int) AS by_percentage
      FROM feature_flags f CROSS JOIN t
     WHERE f.status = 'active'
  )
  SELECT code,
         COALESCE(by_tenant, by_plan, by_beta, by_country, by_percentage, default_enabled),
         CASE WHEN by_tenant IS NOT NULL THEN 'tenant' WHEN by_plan IS NOT NULL THEN 'plan'
              WHEN by_beta IS NOT NULL THEN 'beta_group' WHEN by_country IS NOT NULL THEN 'country'
              WHEN by_percentage IS NOT NULL THEN 'percentage' ELSE 'default' END
    FROM ev
$$;
SQL);
    }

    public function down(): void {}
};
