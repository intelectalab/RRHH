-- ====================================================================================================
-- GestiaHR · Pruebas de aislamiento entre organizaciones y de registros inalterables (incluye el libro de cumplimiento encadenado)
--
-- Uso (con el rol propietario del esquema o un superusuario, sobre una base con schema.sql cargado):
--   psql -v ON_ERROR_STOP=1 -d gestiahr -f pruebas_aislamiento.sql
--
-- Todo corre dentro de una transacción que termina en ROLLBACK: no deja datos y se puede repetir.
-- Cada bloque lanza una excepción («FALLO ...») si la garantía no se cumple; si todo va bien,
-- la última línea es «TODAS LAS PRUEBAS PASARON».
--
-- Las comprobaciones de aislamiento se hacen con SET ROLE gestia_app (rol sin BYPASSRLS). Ojo: un
-- superusuario y los roles con BYPASSRLS no están sujetos a RLS; por eso la aplicación de las
-- organizaciones debe conectarse siempre con un rol que herede de gestia_app.
-- ====================================================================================================
\set ON_ERROR_STOP on
\set QUIET on
\pset tuples_only on
SET client_min_messages = notice;

BEGIN;

-- ----------------------------------------------------------------------------------------------------
-- 0. Comprobaciones estructurales sobre el catálogo
-- ----------------------------------------------------------------------------------------------------
DO $$
DECLARE
  n integer;
  lista text;
BEGIN
  IF (SELECT rolbypassrls OR rolsuper FROM pg_roles WHERE rolname = 'gestia_app') THEN
    RAISE EXCEPTION 'FALLO 0.1: gestia_app no debe tener BYPASSRLS ni ser superusuario';
  END IF;
  RAISE NOTICE 'OK 0.1  gestia_app no tiene BYPASSRLS ni es superusuario';

  -- Toda tabla que no esté en la lista cerrada de tablas de plataforma debe llevar tenant_id NOT NULL,
  -- RLS activado y forzado, y la política tenant_isolation.
  SELECT count(*), string_agg(c.relname, ', ') INTO n, lista
    FROM pg_class c
    JOIN pg_namespace ns ON ns.oid = c.relnamespace AND ns.nspname = 'public'
   WHERE c.relkind IN ('r', 'p')
     -- Una partición se juzga por el nombre de su tabla madre (decisión A-06).
     AND COALESCE((SELECT pc.relname FROM pg_inherits i JOIN pg_class pc ON pc.oid = i.inhparent WHERE i.inhrelid = c.oid), c.relname) NOT IN (
       'addon_modules', 'addons', 'backup_restores', 'backup_runs', 'compliance_obligation_templates', 'countries',
       'country_concept_templates', 'country_document_templates', 'country_holidays', 'country_pack_versions',
       'country_packs', 'currencies', 'feature_flag_rules', 'feature_flags', 'help_article_versions', 'help_articles', 'integration_connectors', 'labor_regimes',
       'legal_parameter_changes', 'legal_parameter_publications', 'legal_parameters', 'master_catalog_items', 'master_catalogs', 'metric_definitions', 'module_dependencies', 'modules', 'parameter_definitions',
       'partner_firm_tenants', 'partner_firm_users', 'partner_firms', 'pe_parametric_items', 'pe_parametric_tables',
       'pension_fund_rates', 'pension_funds', 'permissions', 'plan_features', 'plan_limits', 'plan_modules', 'plans', 'platform_audit_events',
       'platform_incidents', 'platform_notices', 'platform_sessions', 'platform_settings', 'platform_usage_snapshots', 'platform_users', 'saas_invoice_lines',
       'saas_invoices', 'saas_payments', 'statutory_file_layouts', 'subscription_addons', 'subscriptions', 'support_ticket_messages', 'support_tickets',
       'system_role_permissions', 'system_roles', 'tenant_domains', 'tenant_status_events', 'tenants',
       'web_chat_sessions', 'web_complaints', 'web_demo_bookings', 'web_leads', 'web_product_modules', 'web_products', 'web_signups')
     AND NOT (c.relrowsecurity AND c.relforcerowsecurity
              AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND a.attnotnull)
              AND EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = 'tenant_isolation'
                             AND p.polcmd = '*' AND p.polpermissive AND p.polwithcheck IS NOT NULL));
  IF n > 0 THEN
    RAISE EXCEPTION 'FALLO 0.2: tablas de organización sin RLS forzado o sin política: %', lista;
  END IF;
  SELECT count(*) INTO n FROM pg_policy WHERE polname = 'tenant_isolation';
  RAISE NOTICE 'OK 0.2  las % tablas de organización tienen RLS activado, forzado y política tenant_isolation', n;

  -- Toda clave foránea entre dos tablas de organización debe incluir tenant_id en ambos lados.
  SELECT count(*), string_agg(k.conname, ', ') INTO n, lista
    FROM pg_constraint k
    JOIN pg_policy p1 ON p1.polrelid = k.conrelid AND p1.polname = 'tenant_isolation'
    JOIN pg_policy p2 ON p2.polrelid = k.confrelid AND p2.polname = 'tenant_isolation'
   WHERE k.contype = 'f'
     AND NOT (
       EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = k.conrelid AND a.attnum = k.conkey[1] AND a.attname = 'tenant_id')
       AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = k.confrelid AND a.attnum = k.confkey[1] AND a.attname = 'tenant_id'));
  IF n > 0 THEN
    RAISE EXCEPTION 'FALLO 0.3: claves foráneas entre tablas de organización sin tenant_id: %', lista;
  END IF;
  SELECT count(*) INTO n
    FROM pg_constraint k
    JOIN pg_policy p1 ON p1.polrelid = k.conrelid AND p1.polname = 'tenant_isolation'
    JOIN pg_policy p2 ON p2.polrelid = k.confrelid AND p2.polname = 'tenant_isolation'
   WHERE k.contype = 'f';
  RAISE NOTICE 'OK 0.3  las % claves foráneas entre tablas de organización son compuestas (tenant_id, x_id)', n;
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- 1. Dos organizaciones y sus valores por defecto (lo hace la plataforma, no la aplicación)
-- ----------------------------------------------------------------------------------------------------
INSERT INTO tenants (id, slug, name, country_code, default_currency) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000001', 'prueba-a', 'Organización A', 'PE', 'PEN'),
  ('bbbbbbbb-0000-4000-8000-000000000002', 'prueba-b', 'Organización B', 'PE', 'PEN');

SELECT seed_tenant_defaults('aaaaaaaa-0000-4000-8000-000000000001');
SELECT seed_tenant_defaults('bbbbbbbb-0000-4000-8000-000000000002');
SELECT seed_tenant_defaults('aaaaaaaa-0000-4000-8000-000000000001');  -- idempotente

DO $$
DECLARE
  n integer;
  m integer;
BEGIN
  SELECT count(*) INTO n FROM roles WHERE tenant_id = 'aaaaaaaa-0000-4000-8000-000000000001' AND is_system;
  SELECT count(*) INTO m FROM system_roles WHERE realm = 'tenant';
  IF n <> m OR n <> 10 THEN
    RAISE EXCEPTION 'FALLO 1.1: se esperaban 10 roles de sistema por organización y hay % (plantilla: %)', n, m;
  END IF;
  SELECT count(*) INTO n FROM role_permissions WHERE tenant_id = 'aaaaaaaa-0000-4000-8000-000000000001';
  SELECT count(*) INTO m FROM system_role_permissions srp JOIN system_roles sr ON sr.code = srp.role_code WHERE sr.realm = 'tenant';
  IF n <> m OR n = 0 THEN
    RAISE EXCEPTION 'FALLO 1.2: permisos sembrados % distinto de la plantilla %', n, m;
  END IF;
  IF EXISTS (SELECT 1 FROM role_permissions WHERE permission_code IN
       (SELECT permission_code FROM system_role_permissions GROUP BY 1 HAVING bool_and(scope = 'platform'))) THEN
    RAISE EXCEPTION 'FALLO 1.3: se copiaron permisos de la consola SaaS a una organización';
  END IF;
  RAISE NOTICE 'OK 1    seed_tenant_defaults creó 10 roles y % permisos por organización, sin duplicar al repetirse', n;
END
$$;

-- SAS-8 · Una sesión de soporte de A (la crea la consola con el rol de plataforma).
INSERT INTO platform_users (id, email, full_name, role) VALUES ('99999999-0000-4000-8000-0000000000e1', 'soporte@aislamiento.test', 'Soporte Aislamiento', 'support');
SET LOCAL ROLE gestia_platform;
INSERT INTO support_sessions (id, tenant_id, platform_user_id, agent_name, target_user_id, reason, expires_at)
VALUES ('99999999-0000-4000-8000-0000000000e2', 'aaaaaaaa-0000-4000-8000-000000000001', '99999999-0000-4000-8000-0000000000e1', 'Soporte Aislamiento', 'aaaaaaaa-0000-4000-8000-0000000000a9', 'Prueba de aislamiento', now() + interval '1 hour');
RESET ROLE;

-- A partir de aquí, todo se ejecuta como la aplicación.
SET LOCAL ROLE gestia_app;

-- ----------------------------------------------------------------------------------------------------
-- 2. Datos de cada organización, insertados con su propia sesión
-- ----------------------------------------------------------------------------------------------------
SELECT set_config('app.tenant_id', 'aaaaaaaa-0000-4000-8000-000000000001', true);
INSERT INTO legal_entities (id, tenant_id, legal_name, country_code, tax_id, currency, timezone) VALUES
  ('aaaaaaaa-0000-4000-8000-0000000000e1', 'aaaaaaaa-0000-4000-8000-000000000001', 'Empresa A SAC', 'PE', '20100000001', 'PEN', 'America/Lima');
INSERT INTO people (id, tenant_id, first_names, paternal_surname) VALUES
  ('aaaaaaaa-0000-4000-8000-0000000000b1', 'aaaaaaaa-0000-4000-8000-000000000001', 'Ana', 'Quispe');
INSERT INTO employments (id, tenant_id, person_id, legal_entity_id, employee_code, hire_date) VALUES
  ('aaaaaaaa-0000-4000-8000-0000000000c1', 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000b1', 'aaaaaaaa-0000-4000-8000-0000000000e1', 'A-0001', '2026-01-05');
INSERT INTO audit_events (id, tenant_id, action, entity_type, entity_id) VALUES
  ('aaaaaaaa-0000-4000-8000-0000000000d1', 'aaaaaaaa-0000-4000-8000-000000000001', 'employment.created', 'employment', 'aaaaaaaa-0000-4000-8000-0000000000c1');
INSERT INTO attendance_punches (id, tenant_id, employment_id, punched_at, kind, method) VALUES
  ('aaaaaaaa-0000-4000-8000-0000000000f1', 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000c1', '2026-10-01 08:02:00-05', 'in', 'web');

SELECT set_config('app.tenant_id', 'bbbbbbbb-0000-4000-8000-000000000002', true);
INSERT INTO legal_entities (id, tenant_id, legal_name, country_code, tax_id, currency, timezone) VALUES
  ('bbbbbbbb-0000-4000-8000-0000000000e2', 'bbbbbbbb-0000-4000-8000-000000000002', 'Empresa B SAC', 'PE', '20200000002', 'PEN', 'America/Lima');
INSERT INTO people (id, tenant_id, first_names, paternal_surname) VALUES
  ('bbbbbbbb-0000-4000-8000-0000000000b2', 'bbbbbbbb-0000-4000-8000-000000000002', 'Bruno', 'Mamani');
INSERT INTO employments (id, tenant_id, person_id, legal_entity_id, employee_code, hire_date) VALUES
  ('bbbbbbbb-0000-4000-8000-0000000000c2', 'bbbbbbbb-0000-4000-8000-000000000002', 'bbbbbbbb-0000-4000-8000-0000000000b2', 'bbbbbbbb-0000-4000-8000-0000000000e2', 'B-0001', '2026-02-02');
INSERT INTO audit_events (id, tenant_id, action, entity_type) VALUES
  ('bbbbbbbb-0000-4000-8000-0000000000d2', 'bbbbbbbb-0000-4000-8000-000000000002', 'employment.created', 'employment');
INSERT INTO attendance_punches (id, tenant_id, employment_id, punched_at, kind, method) VALUES
  ('bbbbbbbb-0000-4000-8000-0000000000f2', 'bbbbbbbb-0000-4000-8000-000000000002', 'bbbbbbbb-0000-4000-8000-0000000000c2', '2026-10-01 08:15:00-05', 'in', 'app');

-- ----------------------------------------------------------------------------------------------------
-- 3. Sin organización fijada se ven 0 filas (y no hay error)
-- ----------------------------------------------------------------------------------------------------
SELECT set_config('app.tenant_id', '', true);
DO $$
DECLARE
  t record;
  n bigint;
  revisadas integer := 0;
BEGIN
  FOR t IN
    SELECT c.relname FROM pg_class c JOIN pg_policy p ON p.polrelid = c.oid AND p.polname = 'tenant_isolation'
     WHERE c.relkind IN ('r', 'p') AND NOT c.relispartition ORDER BY c.relname
  LOOP
    EXECUTE format('SELECT count(*) FROM %I', t.relname) INTO n;
    IF n <> 0 THEN
      RAISE EXCEPTION 'FALLO 3: sin organización fijada, % devuelve % filas', t.relname, n;
    END IF;
    revisadas := revisadas + 1;
  END LOOP;
  -- Hay datos reales detrás (roles sembrados, personas, marcaciones): que se vean 0 no es por tablas vacías.
  RAISE NOTICE 'OK 3    sin app.tenant_id, las % tablas de organización devuelven 0 filas', revisadas;
END
$$;

DO $$
BEGIN
  BEGIN
    INSERT INTO people (tenant_id, first_names, paternal_surname)
    VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'Sin', 'Sesión');
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'OK 3b   sin app.tenant_id no se puede insertar en ninguna organización';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 3b: se pudo insertar sin organización fijada';
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- 4. Una organización no ve ni toca las filas de la otra
-- ----------------------------------------------------------------------------------------------------
SELECT set_config('app.tenant_id', 'aaaaaaaa-0000-4000-8000-000000000001', true);
DO $$
DECLARE
  n integer;
BEGIN
  SELECT count(*) INTO n FROM people;
  IF n <> 1 THEN RAISE EXCEPTION 'FALLO 4.1: A ve % personas (esperado 1)', n; END IF;
  IF EXISTS (SELECT 1 FROM people WHERE tenant_id <> 'aaaaaaaa-0000-4000-8000-000000000001') THEN
    RAISE EXCEPTION 'FALLO 4.2: A ve personas de otra organización';
  END IF;
  SELECT count(*) INTO n FROM people WHERE id = 'bbbbbbbb-0000-4000-8000-0000000000b2';
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 4.3: A ve por id a una persona de B'; END IF;
  SELECT count(*) INTO n FROM employments e JOIN legal_entities le ON le.id = e.legal_entity_id;
  IF n <> 1 THEN RAISE EXCEPTION 'FALLO 4.4: A ve % relaciones laborales (esperado 1)', n; END IF;
  SELECT count(*) INTO n FROM roles;
  IF n <> 10 THEN RAISE EXCEPTION 'FALLO 4.5: A ve % roles (esperado 10)', n; END IF;
  SELECT count(*) INTO n FROM attendance_punches;
  IF n <> 1 THEN RAISE EXCEPTION 'FALLO 4.6: A ve % marcaciones (esperado 1)', n; END IF;

  UPDATE people SET preferred_name = 'intruso' WHERE id = 'bbbbbbbb-0000-4000-8000-0000000000b2';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 4.7: A modificó una persona de B'; END IF;
  DELETE FROM people WHERE id = 'bbbbbbbb-0000-4000-8000-0000000000b2';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 4.8: A borró una persona de B'; END IF;

  SELECT count(*) INTO n FROM current_tenant;
  IF n <> 1 OR (SELECT slug FROM current_tenant) <> 'prueba-a' THEN
    RAISE EXCEPTION 'FALLO 4.9: current_tenant no devuelve solo la organización activa';
  END IF;
  RAISE NOTICE 'OK 4    A solo ve y solo puede tocar sus filas; UPDATE y DELETE sobre filas de B afectan 0 filas';
END
$$;

DO $$
BEGIN
  BEGIN
    PERFORM 1 FROM tenants;
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'OK 4b   la aplicación no puede leer la tabla tenants (solo la vista current_tenant)';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 4b: gestia_app puede leer la lista de organizaciones';
END
$$;

SELECT set_config('app.tenant_id', 'bbbbbbbb-0000-4000-8000-000000000002', true);
DO $$
DECLARE
  n integer;
BEGIN
  SELECT count(*) INTO n FROM people WHERE tenant_id = 'aaaaaaaa-0000-4000-8000-000000000001';
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 4.10: B ve personas de A'; END IF;
  SELECT count(*) INTO n FROM audit_events;
  IF n <> 1 THEN RAISE EXCEPTION 'FALLO 4.11: B ve % eventos de auditoría (esperado 1)', n; END IF;
  SELECT count(*) INTO n FROM support_sessions;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 4.12: B ve % sesiones de soporte de A', n; END IF;
  RAISE NOTICE 'OK 4c   B tampoco ve las filas de A (ni sus sesiones de soporte)';
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- 5. Insertar o mover una fila a otra organización falla
-- ----------------------------------------------------------------------------------------------------
DO $$
BEGIN
  BEGIN
    INSERT INTO people (tenant_id, first_names, paternal_surname)
    VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'Intruso', 'Desde B');
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'OK 5.1  con sesión de B, insertar con tenant_id de A falla (política RLS, 42501)';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 5.1: B insertó una fila con tenant_id de A';
END
$$;

DO $$
BEGIN
  BEGIN
    UPDATE people SET tenant_id = 'aaaaaaaa-0000-4000-8000-000000000001'
     WHERE id = 'bbbbbbbb-0000-4000-8000-0000000000b2';
  EXCEPTION WHEN insufficient_privilege OR foreign_key_violation THEN
    RAISE NOTICE 'OK 5.2  una fila no puede cambiarse a otra organización';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 5.2: B trasladó una fila a la organización A';
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- 6. Una clave foránea cruzada entre organizaciones falla
-- ----------------------------------------------------------------------------------------------------
DO $$
BEGIN
  BEGIN
    -- Sesión de B, fila de B, pero apuntando a la persona de A.
    INSERT INTO employments (tenant_id, person_id, legal_entity_id, employee_code, hire_date)
    VALUES ('bbbbbbbb-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-0000000000b1',
            'bbbbbbbb-0000-4000-8000-0000000000e2', 'B-0666', '2026-03-01');
  EXCEPTION WHEN foreign_key_violation THEN
    RAISE NOTICE 'OK 6.1  como aplicación: una relación laboral de B no puede referenciar a una persona de A (23503)';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 6.1: se creó una referencia cruzada entre organizaciones';
END
$$;

-- La garantía es de la clave foránea compuesta, no de RLS: también falla para un rol que ignora RLS.
RESET ROLE;
DO $$
BEGIN
  BEGIN
    INSERT INTO employments (tenant_id, person_id, legal_entity_id, employee_code, hire_date)
    VALUES ('bbbbbbbb-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-0000000000b1',
            'bbbbbbbb-0000-4000-8000-0000000000e2', 'B-0667', '2026-03-01');
  EXCEPTION WHEN foreign_key_violation THEN
    RAISE NOTICE 'OK 6.2  sin RLS (propietario): la clave foránea compuesta impide igual la referencia cruzada';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 6.2: la clave foránea compuesta no impidió la referencia cruzada';
END
$$;

DO $$
BEGIN
  BEGIN
    INSERT INTO attendance_punches (tenant_id, employment_id, punched_at, kind, method)
    VALUES ('bbbbbbbb-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-0000000000c1', now(), 'in', 'web');
  EXCEPTION WHEN foreign_key_violation THEN
    RAISE NOTICE 'OK 6.3  una marcación de B no puede referenciar una relación laboral de A';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 6.3: marcación con referencia cruzada';
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- 7. audit_events y attendance_punches rechazan UPDATE y DELETE
-- ----------------------------------------------------------------------------------------------------
-- 7a. Como aplicación (dentro de su propia organización): no tiene el privilegio.
SET LOCAL ROLE gestia_app;
SELECT set_config('app.tenant_id', 'aaaaaaaa-0000-4000-8000-000000000001', true);
DO $$
DECLARE
  tabla text;
  sentencia text;
BEGIN
  FOREACH tabla IN ARRAY ARRAY['audit_events', 'attendance_punches'] LOOP
    FOREACH sentencia IN ARRAY ARRAY['UPDATE %I SET created_at = now()', 'DELETE FROM %I'] LOOP
      BEGIN
        EXECUTE format(sentencia, tabla);
        RAISE EXCEPTION 'FALLO 7a: la aplicación pudo ejecutar «%» sobre %', sentencia, tabla USING ERRCODE = 'AY999';
      EXCEPTION
        WHEN insufficient_privilege OR SQLSTATE 'AY001' THEN NULL;
      END;
    END LOOP;
  END LOOP;
  RAISE NOTICE 'OK 7a   como aplicación: UPDATE y DELETE rechazados en audit_events y attendance_punches';
END
$$;

-- 7b. Como propietario (con todos los privilegios y sin RLS): lo impide el trigger (SQLSTATE AY001).
RESET ROLE;
DO $$
DECLARE
  tabla text;
  sentencia text;
  n integer := 0;
BEGIN
  FOREACH tabla IN ARRAY ARRAY['audit_events', 'attendance_punches'] LOOP
    FOREACH sentencia IN ARRAY ARRAY['UPDATE %I SET created_at = now()', 'DELETE FROM %I', 'TRUNCATE %I CASCADE'] LOOP
      BEGIN
        EXECUTE format(sentencia, tabla);
        RAISE EXCEPTION 'FALLO 7b: el propietario pudo ejecutar «%» sobre %', sentencia, tabla USING ERRCODE = 'AY999';
      EXCEPTION
        WHEN SQLSTATE 'AY001' THEN n := n + 1;
      END;
    END LOOP;
  END LOOP;
  IF (SELECT count(*) FROM audit_events) <> 2 OR (SELECT count(*) FROM attendance_punches) <> 2 THEN
    RAISE EXCEPTION 'FALLO 7b: cambió el número de filas de las tablas inalterables';
  END IF;
  RAISE NOTICE 'OK 7b   como propietario: el trigger rechaza UPDATE, DELETE y TRUNCATE (% intentos, SQLSTATE AY001)', n;
END
$$;

-- 7c. La corrección de una marcación es una fila nueva que apunta a la original.
SET LOCAL ROLE gestia_app;
INSERT INTO attendance_punches (tenant_id, employment_id, punched_at, kind, method, entry_type, corrects_punch_id, correction_reason)
VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000c1', '2026-10-01 08:00:00-05', 'in', 'manual',
        'correction', 'aaaaaaaa-0000-4000-8000-0000000000f1', 'El reloj estaba adelantado');
DO $$
BEGIN
  BEGIN
    INSERT INTO attendance_punches (tenant_id, employment_id, punched_at, kind, method, entry_type)
    VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000c1', now(), 'in', 'manual', 'correction');
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'OK 7c   la corrección es una fila nueva y exige marcación original y motivo';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 7c: se aceptó una corrección sin marcación original ni motivo';
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- 8. Otras garantías del modelo
-- ----------------------------------------------------------------------------------------------------
-- 8.1 Vigencias: no se admiten dos asignaciones solapadas para la misma relación laboral.
INSERT INTO sites (id, tenant_id, code, name) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a01', 'aaaaaaaa-0000-4000-8000-000000000001', 'LIM', 'Lima');
INSERT INTO org_unit_types (id, tenant_id, code, name, rank) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a04', 'aaaaaaaa-0000-4000-8000-000000000001', 'area', 'Área', 2);
INSERT INTO org_units (id, tenant_id, code, name, org_unit_type_id) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a02', 'aaaaaaaa-0000-4000-8000-000000000001', 'CONTA', 'Contabilidad', 'aaaaaaaa-0000-4000-8000-000000000a04');
INSERT INTO job_profiles (id, tenant_id, code, name) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a03', 'aaaaaaaa-0000-4000-8000-000000000001', 'ANA-CONT', 'Analista Contable');
INSERT INTO employment_assignments (tenant_id, employment_id, job_profile_id, org_unit_id, site_id, valid_from, valid_to) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000c1', 'aaaaaaaa-0000-4000-8000-000000000a03',
   'aaaaaaaa-0000-4000-8000-000000000a02', 'aaaaaaaa-0000-4000-8000-000000000a01', '2026-01-05', '2026-06-30');
-- Un cambio con fecha futura contigua sí entra.
INSERT INTO employment_assignments (tenant_id, employment_id, job_profile_id, org_unit_id, site_id, valid_from, valid_to) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000c1', 'aaaaaaaa-0000-4000-8000-000000000a03',
   'aaaaaaaa-0000-4000-8000-000000000a02', 'aaaaaaaa-0000-4000-8000-000000000a01', '2026-07-01', NULL);
DO $$
BEGIN
  BEGIN
    INSERT INTO employment_assignments (tenant_id, employment_id, job_profile_id, org_unit_id, site_id, valid_from, valid_to) VALUES
      ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-0000000000c1', 'aaaaaaaa-0000-4000-8000-000000000a03',
       'aaaaaaaa-0000-4000-8000-000000000a02', 'aaaaaaaa-0000-4000-8000-000000000a01', '2026-06-15', '2026-12-31');
  EXCEPTION WHEN exclusion_violation THEN
    RAISE NOTICE 'OK 8.1  vigencias: una asignación solapada se rechaza (23P01); la contigua con fecha futura se acepta';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 8.1: se aceptó una asignación con vigencia solapada';
END
$$;

-- 8.2 Una boleta cerrada no se modifica ni se borra.
INSERT INTO payroll_groups (id, tenant_id, code, name, legal_entity_id, currency) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a10', 'aaaaaaaa-0000-4000-8000-000000000001', 'MENSUAL', 'Planilla mensual', 'aaaaaaaa-0000-4000-8000-0000000000e1', 'PEN');
INSERT INTO payroll_periods (id, tenant_id, payroll_group_id, period_year, period_number, starts_on, ends_on) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a11', 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000a10', 2026, 9, '2026-09-01', '2026-09-30');
INSERT INTO payslips (id, tenant_id, payroll_period_id, employment_id, legal_entity_id, number, currency, content) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a12', 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000a11',
   'aaaaaaaa-0000-4000-8000-0000000000c1', 'aaaaaaaa-0000-4000-8000-0000000000e1', 'B-2026-09-0001', 'PEN', '{}');
UPDATE payslips SET net_amount = 100 WHERE id = 'aaaaaaaa-0000-4000-8000-000000000a12';                   -- borrador: se puede
UPDATE payslips SET status = 'closed', closed_at = now() WHERE id = 'aaaaaaaa-0000-4000-8000-000000000a12'; -- cierre
DO $$
DECLARE
  sentencia text;
BEGIN
  FOREACH sentencia IN ARRAY ARRAY['UPDATE payslips SET net_amount = 1', 'DELETE FROM payslips'] LOOP
    BEGIN
      EXECUTE sentencia;
      RAISE EXCEPTION 'FALLO 8.2: se pudo ejecutar «%» sobre una boleta cerrada', sentencia USING ERRCODE = 'AY999';
    EXCEPTION WHEN SQLSTATE 'AY002' THEN NULL;
    END;
  END LOOP;
  IF (SELECT lock_version FROM payslips WHERE id = 'aaaaaaaa-0000-4000-8000-000000000a12') <> 3 THEN
    RAISE EXCEPTION 'FALLO 8.2: lock_version no se incrementó en cada actualización';
  END IF;
  RAISE NOTICE 'OK 8.2  una boleta cerrada rechaza UPDATE y DELETE (AY002); lock_version se incrementa solo';
END
$$;

-- 8.3 Denuncia anónima: no admite identidad del denunciante.
INSERT INTO complaint_channels (id, tenant_id, name, public_slug) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a20', 'aaaaaaaa-0000-4000-8000-000000000001', 'Canal de denuncias', 'canal-a');
INSERT INTO complaints (id, tenant_id, complaint_channel_id, tracking_code_hash, access_secret_hash, is_anonymous, category, description_enc, received_on) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a21', 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000a20',
   'hash-codigo-1', 'hash-secreto-1', true, 'ethics', 'texto-cifrado', '2026-10-01');
DO $$
DECLARE
  n integer;
BEGIN
  SELECT count(*) INTO n FROM information_schema.columns
   WHERE table_name = 'complaints'
     AND (column_name ~ '(^|_)(user|person|employment|reporter)_id$' OR column_name ~ '(^|_)ip($|_)'
          OR column_name IN ('user_agent', 'created_at', 'updated_at', 'email', 'phone'));
  IF n <> 0 THEN
    RAISE EXCEPTION 'FALLO 8.3: complaints tiene % columnas que podrían identificar al denunciante', n;
  END IF;
  BEGIN
    INSERT INTO complaint_identities (tenant_id, complaint_id, reporter_name_enc)
    VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000a21', 'cifrado');
  EXCEPTION WHEN SQLSTATE 'AY003' THEN
    RAISE NOTICE 'OK 8.3  denuncias: sin columnas que identifiquen al denunciante; una denuncia anónima no admite identidad (AY003)';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 8.3: se registró la identidad en una denuncia anónima';
END
$$;

-- 8.4 Encuesta anónima: la respuesta no tiene vínculo con la persona.
INSERT INTO surveys (id, tenant_id, title, kind, is_anonymous) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a30', 'aaaaaaaa-0000-4000-8000-000000000001', 'Clima 2026', 'climate', true);
INSERT INTO survey_responses (id, tenant_id, survey_id, submitted_on) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000a31', 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000a30', '2026-10-01');
DO $$
DECLARE
  n integer;
BEGIN
  SELECT count(*) INTO n
    FROM pg_constraint k
   WHERE k.contype = 'f' AND k.conrelid IN ('survey_responses'::regclass, 'survey_answers'::regclass)
     AND k.confrelid IN ('people'::regclass, 'users'::regclass, 'employments'::regclass);
  IF n <> 0 THEN
    RAISE EXCEPTION 'FALLO 8.4: las respuestas de encuesta tienen % claves foráneas hacia personas', n;
  END IF;
  SELECT count(*) INTO n FROM information_schema.columns
   WHERE table_name IN ('survey_responses', 'survey_answers')
     AND column_name IN ('created_at', 'updated_at', 'user_id', 'person_id', 'employment_id', 'ip');
  IF n <> 0 THEN
    RAISE EXCEPTION 'FALLO 8.4: las respuestas de encuesta tienen columnas que permiten reidentificar';
  END IF;
  BEGIN
    INSERT INTO survey_response_identities (tenant_id, survey_response_id, employment_id)
    VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000a31', 'aaaaaaaa-0000-4000-8000-0000000000c1');
  EXCEPTION WHEN SQLSTATE 'AY003' THEN
    RAISE NOTICE 'OK 8.4  encuestas: respuestas sin clave foránea ni marcas de tiempo; una encuesta anónima no admite identidad (AY003)';
    RETURN;
  END;
  RAISE EXCEPTION 'FALLO 8.4: se vinculó una respuesta anónima con una persona';
END
$$;

-- 8.5 Libro inalterable de cumplimiento: encadenado por hash, solo inserción, sin identidad ni hora en denuncias.
INSERT INTO compliance_ledger_entries (tenant_id, chain_key, seq, entry_type, subject_type, subject_id, payload, payload_canonical,
                                       payload_hash, prev_hash, entry_hash, actor_kind, occurred_at)
SELECT 'aaaaaaaa-0000-4000-8000-000000000001', 'complaint:aaaaaaaa-0000-4000-8000-000000000a21', 0, v.t, 'complaint',
       'aaaaaaaa-0000-4000-8000-000000000a21', v.p::jsonb, v.p, repeat('0', 64), repeat('0', 64), repeat('0', 64), v.k,
       timestamptz '2026-10-01 15:42:10+00'
  FROM (VALUES (1, 'complaint.received', '{"stage":"received"}', 'anonymous_reporter'),
               (2, 'complaint.care_offered', '{"channel":"medico"}', 'system'),
               (3, 'complaint.referred', '{"stage":"referred_to_committee"}', 'system')) AS v(o, t, p, k)
 ORDER BY v.o;
DO $$
DECLARE
  n integer;
  r record;
  v_prev text := repeat('0', 64);
BEGIN
  n := 0;
  FOR r IN SELECT * FROM compliance_ledger_entries ORDER BY seq LOOP
    n := n + 1;
    IF r.seq <> n OR r.prev_hash <> v_prev OR r.entry_hash = repeat('0', 64)
       OR r.payload_hash <> encode(sha256(convert_to(r.payload_canonical, 'UTF8')), 'hex') THEN
      RAISE EXCEPTION 'FALLO 8.5: la fila % del libro no está encadenada con la anterior', r.seq;
    END IF;
    IF r.recorded_at <> date_trunc('day', r.recorded_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'
       OR r.occurred_at <> timestamptz '2026-10-01 00:00:00+00' THEN
      RAISE EXCEPTION 'FALLO 8.5: el libro de una denuncia guarda la hora del asiento';
    END IF;
    v_prev := r.entry_hash;
  END LOOP;
  IF n <> 3 THEN RAISE EXCEPTION 'FALLO 8.5: se esperaban 3 asientos y hay %', n; END IF;
  SELECT count(*) INTO n FROM verify_compliance_chain('aaaaaaaa-0000-4000-8000-000000000001', 'complaint:aaaaaaaa-0000-4000-8000-000000000a21');
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 8.5: la verificación reporta roturas en una cadena íntegra'; END IF;
  SELECT count(*) INTO n FROM information_schema.columns
   WHERE table_name = 'compliance_ledger_entries'
     AND (column_name ~ '(^|_)ip($|_)' OR column_name IN ('user_agent', 'session_id', 'device', 'created_at', 'updated_at'));
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 8.5: el libro tiene % columnas que permiten reidentificar', n; END IF;
  RAISE NOTICE 'OK 8.5a libro de cumplimiento: 3 asientos encadenados, verificación íntegra, sin IP ni hora en denuncias';
END
$$;
DO $$
BEGIN
  BEGIN
    UPDATE compliance_ledger_entries SET entry_type = 'x' WHERE seq = 2;
    RAISE EXCEPTION 'FALLO 8.5: se pudo actualizar el libro de cumplimiento';
  EXCEPTION WHEN insufficient_privilege OR SQLSTATE 'AY001' THEN NULL;
  END;
  BEGIN
    DELETE FROM compliance_ledger_entries WHERE seq = 2;
    RAISE EXCEPTION 'FALLO 8.5: se pudo borrar del libro de cumplimiento';
  EXCEPTION WHEN insufficient_privilege OR SQLSTATE 'AY001' THEN NULL;
  END;
  BEGIN
    INSERT INTO compliance_ledger_entries (tenant_id, chain_key, seq, entry_type, subject_type, subject_id, payload, payload_canonical,
                                           payload_hash, prev_hash, entry_hash, actor_kind, actor_user_id, occurred_at)
    VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'complaint:x', 0, 'complaint.received', 'complaint', gen_random_uuid(), '{}', '{}',
            repeat('0', 64), repeat('0', 64), repeat('0', 64), 'anonymous_reporter', gen_random_uuid(), now());
    RAISE EXCEPTION 'FALLO 8.5: un asiento anónimo aceptó un usuario';
  EXCEPTION WHEN check_violation OR foreign_key_violation THEN NULL;
  END;
  RAISE NOTICE 'OK 8.5b libro de cumplimiento: rechaza UPDATE y DELETE, y un asiento anónimo no admite usuario';
END
$$;

RESET ROLE;
-- 8.5c Una alteración hecha saltando el trigger (como lo haría un administrador de base de datos) se detecta al verificar.
ALTER TABLE compliance_ledger_entries DISABLE TRIGGER trg_compliance_ledger_entries_inmutable;
UPDATE compliance_ledger_entries SET payload = '{"channel":"otro"}', payload_canonical = '{"channel":"otro"}' WHERE seq = 2;
ALTER TABLE compliance_ledger_entries ENABLE TRIGGER trg_compliance_ledger_entries_inmutable;
DO $$
DECLARE
  v_seq bigint;
  v_reason text;
BEGIN
  SELECT broken_seq, reason INTO v_seq, v_reason
    FROM verify_compliance_chain('aaaaaaaa-0000-4000-8000-000000000001', 'complaint:aaaaaaaa-0000-4000-8000-000000000a21');
  IF v_seq IS DISTINCT FROM 2 OR v_reason IS DISTINCT FROM 'payload_hash_mismatch' THEN
    RAISE EXCEPTION 'FALLO 8.5c: la alteración de la fila 2 no se detectó (% / %)', v_seq, v_reason;
  END IF;
  RAISE NOTICE 'OK 8.5c libro de cumplimiento: la verificación detecta la fila alterada (%)', v_reason;
END
$$;
-- ----------------------------------------------------------------------------------------------------
-- 9. Identificadores UUID v7 (A-03) y tablas de registro particionadas por mes (A-06)
-- ----------------------------------------------------------------------------------------------------
RESET ROLE;
DO $$
DECLARE
  a uuid;
  b uuid;
  n integer;
BEGIN
  a := uuid_v7(); PERFORM pg_sleep(0.002); b := uuid_v7();
  IF substr(a::text, 15, 1) <> '7' OR substr(a::text, 20, 1) NOT IN ('8', '9', 'a', 'b') THEN
    RAISE EXCEPTION 'FALLO 9.1: uuid_v7() no devuelve versión 7 y variante RFC 9562 (%)', a;
  END IF;
  IF NOT (a::text < b::text) THEN
    RAISE EXCEPTION 'FALLO 9.1: uuid_v7() no es ordenable por tiempo (% >= %)', a, b;
  END IF;
  SELECT count(*) INTO n FROM pg_attrdef d JOIN pg_attribute t ON t.attrelid = d.adrelid AND t.attnum = d.adnum
   JOIN pg_class c ON c.oid = d.adrelid AND c.relnamespace = 'public'::regnamespace AND NOT c.relispartition
   WHERE t.attname = 'id' AND pg_get_expr(d.adbin, d.adrelid) <> 'uuid_v7()';
  IF n > 0 THEN
    RAISE EXCEPTION 'FALLO 9.1: % tablas tienen una clave id con otro DEFAULT que uuid_v7()', n;
  END IF;
  RAISE NOTICE 'OK 9.1  uuid_v7(): versión 7, ordenable, DEFAULT de todas las claves primarias';

  SELECT count(*) INTO n FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'p'
     AND relname IN ('audit_events', 'data_access_logs', 'activity_events', 'login_attempts', 'api_request_logs', 'platform_audit_events');
  IF n <> 6 THEN
    RAISE EXCEPTION 'FALLO 9.2: se esperaban 6 tablas de registro particionadas y hay %', n;
  END IF;
  -- Ensure es idempotente y crea un mes futuro nuevo.
  PERFORM ensure_monthly_partitions('audit_events', (current_date + interval '12 months')::date, 1);
  IF ensure_monthly_partitions('audit_events', (current_date + interval '12 months')::date, 1) <> 0 THEN
    RAISE EXCEPTION 'FALLO 9.2: ensure_monthly_partitions no es idempotente';
  END IF;
  -- Cada partición de una tabla de organización lleva RLS forzado y política; ninguna tiene privilegios directos.
  SELECT count(*) INTO n FROM pg_class c
   WHERE c.relnamespace = 'public'::regnamespace AND c.relispartition AND c.relkind = 'r'
     AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND a.attnotnull)
     AND NOT (c.relforcerowsecurity AND EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = 'tenant_isolation'));
  IF n > 0 THEN
    RAISE EXCEPTION 'FALLO 9.2: % particiones sin RLS forzado o sin política', n;
  END IF;
  SELECT count(*) INTO n FROM pg_class c
   WHERE c.relnamespace = 'public'::regnamespace AND c.relispartition AND c.relkind = 'r'
     AND (has_table_privilege('gestia_app', c.oid, 'SELECT') OR has_table_privilege('gestia_app', c.oid, 'INSERT'));
  IF n > 0 THEN
    RAISE EXCEPTION 'FALLO 9.2: gestia_app tiene acceso directo a % particiones', n;
  END IF;
  RAISE NOTICE 'OK 9.2  6 tablas de registro particionadas por mes; particiones con RLS y sin acceso directo';
END
$$;

-- 9.3 Una fila de auditoría cae en la partición del mes y sigue aislada por organización.
SET LOCAL ROLE gestia_app;
SELECT set_config('app.tenant_id', 'aaaaaaaa-0000-4000-8000-000000000001', true);
INSERT INTO audit_events (tenant_id, action, entity_type) VALUES ('aaaaaaaa-0000-4000-8000-000000000001', 'test.partition', 'test');
SELECT set_config('app.tenant_id', 'bbbbbbbb-0000-4000-8000-000000000002', true);
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM audit_events WHERE action = 'test.partition') THEN
    RAISE EXCEPTION 'FALLO 9.3: la organización B ve la auditoría particionada de A';
  END IF;
  BEGIN
    EXECUTE format('SELECT 1 FROM %I', 'audit_events_p' || to_char(current_date, 'YYYYMM'));
    RAISE EXCEPTION 'FALLO 9.3: la aplicación pudo leer una partición directamente' USING ERRCODE = 'AY999';
  EXCEPTION WHEN insufficient_privilege THEN NULL;
  END;
END
$$;
RESET ROLE;
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM ONLY audit_events_default WHERE action = 'test.partition') AND
     EXISTS (SELECT 1 FROM audit_events WHERE action = 'test.partition'
               AND tableoid = ('audit_events_p' || to_char(now() AT TIME ZONE 'UTC', 'YYYYMM'))::regclass) THEN
    RAISE NOTICE 'OK 9.3  auditoría particionada: la fila cae en el mes, B no la ve y la partición no se lee directo';
  ELSE
    RAISE EXCEPTION 'FALLO 9.3: la fila no cayó en la partición del mes';
  END IF;
END
$$;
DO $$ BEGIN RAISE NOTICE 'TODAS LAS PRUEBAS PASARON'; END $$;

ROLLBACK;
