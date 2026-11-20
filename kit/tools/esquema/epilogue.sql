
-- ====================================================================================================
-- REGLAS DE INTEGRIDAD ADICIONALES (triggers específicos)
-- ====================================================================================================

-- BOL · Una boleta cerrada no se modifica ni se borra (SQLSTATE AY002). Una rectificación es una boleta nueva.
CREATE FUNCTION guard_closed_payslip() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  IF OLD.closed_at IS NOT NULL THEN
    RAISE EXCEPTION 'La boleta % está cerrada: % no está permitido. Emite una boleta de rectificación.', OLD.number, TG_OP
      USING ERRCODE = 'AY002';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_payslips_cerrada BEFORE UPDATE OR DELETE ON payslips
  FOR EACH ROW EXECUTE FUNCTION guard_closed_payslip();

-- PER · Una corrida cerrada no se modifica ni se borra, y tampoco sus resultados ni sus líneas.
CREATE FUNCTION guard_closed_payroll_run() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  IF OLD.closed_at IS NOT NULL THEN
    RAISE EXCEPTION 'La corrida de planilla está cerrada: % no está permitido.', TG_OP USING ERRCODE = 'AY002';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_payroll_runs_cerrada BEFORE UPDATE OR DELETE ON payroll_runs
  FOR EACH ROW EXECUTE FUNCTION guard_closed_payroll_run();

CREATE FUNCTION guard_closed_payroll_run_child() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_run uuid;
  v_closed timestamptz;
BEGIN
  IF TG_OP = 'DELETE' THEN v_run := OLD.payroll_run_id; ELSE v_run := NEW.payroll_run_id; END IF;
  SELECT closed_at INTO v_closed FROM payroll_runs WHERE id = v_run;
  IF v_closed IS NOT NULL THEN
    RAISE EXCEPTION 'La corrida de planilla está cerrada: % sobre % no está permitido.', TG_OP, TG_TABLE_NAME
      USING ERRCODE = 'AY002';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_payroll_run_employments_cerrada BEFORE INSERT OR UPDATE OR DELETE ON payroll_run_employments
  FOR EACH ROW EXECUTE FUNCTION guard_closed_payroll_run_child();
CREATE TRIGGER trg_payroll_run_lines_cerrada BEFORE INSERT OR UPDATE OR DELETE ON payroll_run_lines
  FOR EACH ROW EXECUTE FUNCTION guard_closed_payroll_run_child();

-- DEN · La identidad del denunciante solo puede existir en denuncias identificadas (SQLSTATE AY003).
CREATE FUNCTION guard_complaint_identity() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_anon boolean;
BEGIN
  SELECT is_anonymous INTO v_anon FROM complaints WHERE id = NEW.complaint_id;
  IF v_anon IS DISTINCT FROM false THEN
    RAISE EXCEPTION 'La denuncia es anónima: no se puede registrar la identidad del denunciante.' USING ERRCODE = 'AY003';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_complaint_identities_anonimato BEFORE INSERT OR UPDATE ON complaint_identities
  FOR EACH ROW EXECUTE FUNCTION guard_complaint_identity();

-- DEN · Una denuncia anónima no puede dejar de serlo.
CREATE FUNCTION guard_complaint_anonymity() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  IF OLD.is_anonymous AND NOT NEW.is_anonymous THEN
    RAISE EXCEPTION 'Una denuncia anónima no puede convertirse en identificada.' USING ERRCODE = 'AY003';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_complaints_anonimato BEFORE UPDATE ON complaints
  FOR EACH ROW EXECUTE FUNCTION guard_complaint_anonymity();

-- ENC · La identidad del respondiente solo puede existir en encuestas no anónimas (SQLSTATE AY003).
CREATE FUNCTION guard_survey_identity() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_anon boolean;
BEGIN
  SELECT s.is_anonymous INTO v_anon
    FROM survey_responses r JOIN surveys s ON s.id = r.survey_id
   WHERE r.id = NEW.survey_response_id;
  IF v_anon IS DISTINCT FROM false THEN
    RAISE EXCEPTION 'La encuesta es anónima: no se puede vincular la respuesta con una persona.' USING ERRCODE = 'AY003';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_survey_response_identities_anonimato BEFORE INSERT OR UPDATE ON survey_response_identities
  FOR EACH ROW EXECUTE FUNCTION guard_survey_identity();

-- ENC · Una encuesta con respuestas no puede cambiar su condición de anónima.
CREATE FUNCTION guard_survey_anonymity() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  IF NEW.is_anonymous IS DISTINCT FROM OLD.is_anonymous
     AND EXISTS (SELECT 1 FROM survey_responses r WHERE r.survey_id = OLD.id) THEN
    RAISE EXCEPTION 'La encuesta ya tiene respuestas: no se puede cambiar su anonimato.' USING ERRCODE = 'AY003';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_surveys_anonimato BEFORE UPDATE ON surveys
  FOR EACH ROW EXECUTE FUNCTION guard_survey_anonymity();

-- PAI-PE · Tablas paramétricas de la planilla electrónica: validación de códigos con vigencia (SQLSTATE AY010).
-- ¿Existe el código en la tabla paramétrica a la fecha? NULL de entrada = no se valida.
CREATE FUNCTION pe_param_ok(p_table smallint, p_code text, p_on date) RETURNS boolean
LANGUAGE sql STABLE
AS $$
  SELECT p_code IS NULL OR EXISTS (
    SELECT 1 FROM pe_parametric_items i
    WHERE i.table_number = p_table AND i.code = p_code
      AND daterange(i.valid_from, i.valid_to, '[]') @> p_on)
$$;
COMMENT ON FUNCTION pe_param_ok(smallint, text, date) IS 'Valida un código contra pe_parametric_items en una fecha.';

CREATE FUNCTION pe_validate_employee_profile() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_bad text[] := '{}';
BEGIN
  IF NOT pe_param_ok(8::smallint,  NEW.worker_type_code,           NEW.valid_from) THEN v_bad := v_bad || 'worker_type_code'; END IF;
  IF NOT pe_param_ok(33::smallint, NEW.labor_regime_sunat_code,    NEW.valid_from) THEN v_bad := v_bad || 'labor_regime_sunat_code'; END IF;
  IF NOT pe_param_ok(24::smallint, NEW.occupational_category_code, NEW.valid_from) THEN v_bad := v_bad || 'occupational_category_code'; END IF;
  IF NOT pe_param_ok(30::smallint, NEW.occupation_code,            NEW.valid_from) THEN v_bad := v_bad || 'occupation_code'; END IF;
  IF NOT pe_param_ok(9::smallint,  NEW.education_status_code,      NEW.valid_from) THEN v_bad := v_bad || 'education_status_code'; END IF;
  IF NOT pe_param_ok(12::smallint, NEW.contract_type_sunat_code,   NEW.valid_from) THEN v_bad := v_bad || 'contract_type_sunat_code'; END IF;
  IF NOT pe_param_ok(35::smallint, NEW.special_situation_code,     NEW.valid_from) THEN v_bad := v_bad || 'special_situation_code'; END IF;
  IF NOT pe_param_ok(16::smallint, NEW.payment_type_code,          NEW.valid_from) THEN v_bad := v_bad || 'payment_type_code'; END IF;
  IF NOT pe_param_ok(13::smallint, NEW.pay_periodicity_code,       NEW.valid_from) THEN v_bad := v_bad || 'pay_periodicity_code'; END IF;
  IF NOT pe_param_ok(15::smallint, NEW.worker_situation_code,      NEW.valid_from) THEN v_bad := v_bad || 'worker_situation_code'; END IF;
  IF NOT pe_param_ok(25::smallint, NEW.tax_treaty_code,            NEW.valid_from) THEN v_bad := v_bad || 'tax_treaty_code'; END IF;
  IF NOT pe_param_ok(14::smallint, NEW.eps_code,                   NEW.valid_from) THEN v_bad := v_bad || 'eps_code'; END IF;
  IF array_length(v_bad, 1) > 0 THEN
    RAISE EXCEPTION 'Códigos fuera de las tablas paramétricas vigentes: %', array_to_string(v_bad, ', ') USING ERRCODE = 'AY010';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_peruvian_employee_profiles_codes BEFORE INSERT OR UPDATE ON peruvian_employee_profiles
  FOR EACH ROW EXECUTE FUNCTION pe_validate_employee_profile();

-- CUM · Libro inalterable de cumplimiento: cada fila se encadena con la huella de la anterior de su cadena.
-- Asigna seq, prev_hash, payload_hash y entry_hash. La aplicación no los envía.
CREATE FUNCTION compliance_ledger_chain() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  v_prev_seq bigint;
  v_prev_hash text;
BEGIN
  -- Serializa las inserciones de una misma cadena dentro de la organización.
  PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text || '|' || NEW.chain_key, 0));
  SELECT e.seq, e.entry_hash INTO v_prev_seq, v_prev_hash
    FROM compliance_ledger_entries e
   WHERE e.tenant_id = NEW.tenant_id AND e.chain_key = NEW.chain_key
   ORDER BY e.seq DESC
   LIMIT 1;
  NEW.seq := COALESCE(v_prev_seq, 0) + 1;
  NEW.prev_hash := COALESCE(v_prev_hash, repeat('0', 64));
  NEW.recorded_at := clock_timestamp();
  -- Denuncias (C-06): el libro guarda solo la fecha, nunca la hora, para no permitir la reidentificación por horario.
  IF NEW.subject_type = 'complaint' THEN
    NEW.recorded_at := date_trunc('day', NEW.recorded_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC';
    NEW.occurred_at := date_trunc('day', NEW.occurred_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC';
  END IF;
  NEW.hash_algorithm := 'SHA-256';
  NEW.payload_hash := encode(sha256(convert_to(NEW.payload_canonical, 'UTF8')), 'hex');
  NEW.entry_hash := encode(sha256(convert_to(concat_ws('|',
      NEW.prev_hash,
      NEW.tenant_id::text,
      NEW.chain_key,
      NEW.seq::text,
      NEW.entry_type,
      NEW.subject_type,
      NEW.subject_id::text,
      NEW.actor_kind,
      COALESCE(NEW.actor_user_id::text, ''),
      to_char(NEW.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
      NEW.payload_hash), 'UTF8')), 'hex');
  RETURN NEW;
END
$$;
COMMENT ON FUNCTION compliance_ledger_chain() IS 'Encadena cada fila del libro de cumplimiento con la huella de la anterior de su cadena.';
CREATE TRIGGER trg_compliance_ledger_entries_chain BEFORE INSERT ON compliance_ledger_entries
  FOR EACH ROW EXECUTE FUNCTION compliance_ledger_chain();

-- Recalcula la cadena y devuelve la primera fila que no cuadra (ninguna fila = cadena íntegra).
CREATE FUNCTION verify_compliance_chain(p_tenant_id uuid, p_chain_key text)
RETURNS TABLE (broken_seq bigint, reason text)
LANGUAGE plpgsql STABLE
AS $$
DECLARE
  r record;
  v_prev text := repeat('0', 64);
  v_expected_seq bigint := 1;
  v_hash text;
BEGIN
  FOR r IN
    SELECT * FROM compliance_ledger_entries e
     WHERE e.tenant_id = p_tenant_id AND e.chain_key = p_chain_key
     ORDER BY e.seq
  LOOP
    IF r.seq <> v_expected_seq THEN
      broken_seq := v_expected_seq; reason := 'missing_entry'; RETURN NEXT; RETURN;
    END IF;
    IF r.prev_hash <> v_prev THEN
      broken_seq := r.seq; reason := 'prev_hash_mismatch'; RETURN NEXT; RETURN;
    END IF;
    IF r.payload_hash <> encode(sha256(convert_to(r.payload_canonical, 'UTF8')), 'hex') THEN
      broken_seq := r.seq; reason := 'payload_hash_mismatch'; RETURN NEXT; RETURN;
    END IF;
    v_hash := encode(sha256(convert_to(concat_ws('|',
        r.prev_hash, r.tenant_id::text, r.chain_key, r.seq::text, r.entry_type, r.subject_type, r.subject_id::text,
        r.actor_kind, COALESCE(r.actor_user_id::text, ''),
        to_char(r.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        r.payload_hash), 'UTF8')), 'hex');
    IF r.entry_hash <> v_hash THEN
      broken_seq := r.seq; reason := 'entry_hash_mismatch'; RETURN NEXT; RETURN;
    END IF;
    v_prev := r.entry_hash;
    v_expected_seq := v_expected_seq + 1;
  END LOOP;
  RETURN;
END
$$;
COMMENT ON FUNCTION verify_compliance_chain(uuid, text) IS 'Verifica la integridad de una cadena del libro de cumplimiento.';

-- ====================================================================================================
-- DATOS DE CATÁLOGO (plataforma): países, monedas, módulos, regímenes, roles de sistema, permisos,
-- matriz por defecto y parámetros configurables. Coinciden con kit/database/catalogos/*.json.
-- Los parámetros legales y los conceptos de planilla NO se cargan aquí: los instala el paquete de país
-- desde parametros_legales_pe.json y conceptos_pe.json, una vez verificados.
-- ====================================================================================================
{{SEEDS}}

-- ====================================================================================================
-- VISTA Y FUNCIONES PARA LA APLICACIÓN
-- ====================================================================================================

-- La aplicación no lee la tabla tenants: solo ve su propia organización a través de esta vista.
CREATE VIEW current_tenant AS
  SELECT id, slug, name, status, country_code, default_currency, default_locale, timezone, is_demo, trial_ends_at
    FROM tenants
   WHERE id = NULLIF(current_setting('app.tenant_id', true), '')::uuid AND deleted_at IS NULL;
COMMENT ON VIEW current_tenant IS 'Organización activa de la sesión. Única vía por la que gestia_app lee datos de tenants.';

-- Resuelve la organización a partir del dominio de la petición, antes de fijar app.tenant_id.
CREATE FUNCTION resolve_tenant_id(p_domain text) RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp
AS $$
  SELECT d.tenant_id
    FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id
   WHERE d.domain = lower(p_domain) AND t.deleted_at IS NULL
     AND t.status IN ('trial', 'active', 'grace_period', 'suspended')
$$;
COMMENT ON FUNCTION resolve_tenant_id(text) IS 'Devuelve el id de la organización dueña de un dominio. No expone otros datos.';

-- SAS-7 · Banderas de funcionalidad de la organización activa (app.tenant_id). Una sola evaluación para la
-- API y la web (la web la recibe en /me). Orden (RN-SAS-07): organización, plan, grupo beta, país; después el
-- porcentaje gradual (cubeta estable por organización) y, al final, el valor por defecto de la bandera.
-- SECURITY DEFINER: lee el plan y las reglas, que la aplicación no ve, sin exponer más que código y resultado.
CREATE FUNCTION current_feature_flags() RETURNS TABLE (code text, enabled boolean, source text)
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
COMMENT ON FUNCTION current_feature_flags() IS 'SAS-7: banderas de funcionalidad evaluadas para la organización activa (RN-SAS-07).';

-- Crea en una organización los roles de sistema y sus permisos por defecto (idempotente).
-- También deja la política de seguridad por defecto y activa los módulos base.
CREATE FUNCTION seed_tenant_defaults(tenant uuid) RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
  v_prev text := current_setting('app.tenant_id', true);
BEGIN
  IF NOT EXISTS (SELECT 1 FROM tenants t WHERE t.id = tenant) THEN
    RAISE EXCEPTION 'No existe la organización %', tenant;
  END IF;

  -- Las políticas RLS exigen que la sesión esté fijada en la organización que se siembra.
  PERFORM set_config('app.tenant_id', tenant::text, true);

  INSERT INTO roles (tenant_id, code, name, description, is_system, system_role_code)
  SELECT tenant, sr.code, sr.name, sr.description, true, sr.code
    FROM system_roles sr
   WHERE sr.realm = 'tenant'
     AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id = tenant AND r.code = sr.code AND r.deleted_at IS NULL);

  INSERT INTO role_permissions (tenant_id, role_id, permission_code, scope)
  SELECT tenant, r.id, srp.permission_code, srp.scope
    FROM system_role_permissions srp
    JOIN system_roles sr ON sr.code = srp.role_code AND sr.realm = 'tenant'
    JOIN roles r ON r.tenant_id = tenant AND r.system_role_code = srp.role_code AND r.deleted_at IS NULL
  ON CONFLICT (tenant_id, role_id, permission_code) DO NOTHING;

  INSERT INTO security_policies (tenant_id) VALUES (tenant)
  ON CONFLICT (tenant_id) DO NOTHING;

  INSERT INTO tenant_modules (tenant_id, module_code, status, source, activated_at)
  SELECT tenant, m.code, 'active', 'plan', now() FROM modules m WHERE m.is_core
  ON CONFLICT (tenant_id, module_code) DO NOTHING;

  PERFORM set_config('app.tenant_id', COALESCE(v_prev, ''), true);
END
$$;
COMMENT ON FUNCTION seed_tenant_defaults(uuid) IS 'Crea los roles de sistema, sus permisos por defecto, la política de seguridad y los módulos base de una organización. Idempotente.';

-- ====================================================================================================
-- BÚSQUEDA DE TEXTO COMPLETO DEL CENTRO DE AYUDA (AYU-5): índices GIN sobre la columna generada en español
-- ====================================================================================================
CREATE INDEX ix_help_articles_search ON help_articles USING gin (search_vector);
CREATE INDEX ix_kb_articles_search ON kb_articles USING gin (search_vector);

-- ====================================================================================================
-- CADENA DE HASH DE LA AUDITORÍA (AUD-5)
-- Cada entrada de audit_events recibe, al insertarse, su número de secuencia por organización, el hash
-- de la entrada anterior y su propio hash (sha256 de sus campos + el hash anterior). Alterar o borrar
-- una entrada (aun saltándose el trigger de inalterabilidad) rompe la cadena y lo detecta
-- audit:verify-integrity. La cabeza de la cadena solo la escribe el trigger (SECURITY DEFINER).
-- ====================================================================================================
CREATE OR REPLACE FUNCTION audit_event_hash(p_tenant uuid, p_seq bigint, p_prev text, p_id uuid, p_occurred timestamptz,
  p_actor uuid, p_kind text, p_action text, p_entity_type text, p_entity_id uuid, p_before jsonb, p_after jsonb,
  p_fields text[], p_reason text, p_ip inet, p_user_agent text, p_request uuid) RETURNS text
LANGUAGE sql IMMUTABLE
AS $$
  SELECT encode(sha256(convert_to(concat_ws(chr(31),
    p_tenant::text, p_seq::text, coalesce(p_prev, ''), p_id::text, ((extract(epoch FROM p_occurred) * 1000000)::bigint)::text,
    coalesce(p_actor::text, ''), coalesce(p_kind, ''), coalesce(p_action, ''), coalesce(p_entity_type, ''), coalesce(p_entity_id::text, ''),
    coalesce(p_before::text, ''), coalesce(p_after::text, ''), coalesce(array_to_string(p_fields, chr(30)), ''),
    coalesce(p_reason, ''), coalesce(host(p_ip), ''), coalesce(p_user_agent, ''), coalesce(p_request::text, '')), 'UTF8')), 'hex')
$$;
COMMENT ON FUNCTION audit_event_hash(uuid, bigint, text, uuid, timestamptz, uuid, text, text, text, uuid, jsonb, jsonb, text[], text, inet, text, uuid) IS 'Hash sha256 de una entrada de auditoría encadenada a la anterior (AUD-5).';

CREATE OR REPLACE FUNCTION audit_events_chain() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE
  v_seq bigint;
  v_prev text;
BEGIN
  INSERT INTO audit_chain_heads (tenant_id, last_seq) VALUES (NEW.tenant_id, 0) ON CONFLICT (tenant_id) DO NOTHING;
  SELECT last_seq, last_hash INTO v_seq, v_prev FROM audit_chain_heads WHERE tenant_id = NEW.tenant_id FOR UPDATE;
  NEW.chain_seq := v_seq + 1;
  NEW.prev_hash := v_prev;
  NEW.row_hash := audit_event_hash(NEW.tenant_id, NEW.chain_seq, NEW.prev_hash, NEW.id, NEW.occurred_at, NEW.actor_user_id, NEW.actor_kind,
    NEW.action, NEW.entity_type, NEW.entity_id, NEW.before_data, NEW.after_data, NEW.changed_fields, NEW.reason, NEW.ip, NEW.user_agent, NEW.request_id);
  UPDATE audit_chain_heads SET last_seq = NEW.chain_seq, last_hash = NEW.row_hash, updated_at = now() WHERE tenant_id = NEW.tenant_id;
  RETURN NEW;
END
$$;
COMMENT ON FUNCTION audit_events_chain() IS 'Encadena cada entrada nueva de audit_events (secuencia, hash anterior y hash propio) y avanza la cabeza de la organización.';
REVOKE ALL ON FUNCTION audit_events_chain() FROM PUBLIC;
CREATE TRIGGER trg_audit_events_chain BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION audit_events_chain();

-- ====================================================================================================
-- PRIVILEGIOS
-- ====================================================================================================
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC;
REVOKE ALL ON FUNCTION seed_tenant_defaults(uuid) FROM PUBLIC;

-- gestia_platform: consola SaaS; opera sobre todas las tablas y entre organizaciones (BYPASSRLS).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO gestia_platform;
GRANT EXECUTE ON FUNCTION seed_tenant_defaults(uuid) TO gestia_platform;

-- gestia_app: aplicación de las organizaciones. Todas las tablas de organización, siempre bajo RLS.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO gestia_app;
-- Catálogos de plataforma: solo lectura para la aplicación.
REVOKE INSERT, UPDATE, DELETE ON {{CATALOG}} FROM gestia_app;
-- Tablas internas de la plataforma: sin acceso para la aplicación.
REVOKE ALL ON {{PLATFORM_ONLY}} FROM gestia_app;
GRANT SELECT ON current_tenant TO gestia_app;
REVOKE INSERT, UPDATE, DELETE ON current_tenant FROM gestia_app, gestia_platform;

-- Tablas inalterables: además del trigger, ningún rol de aplicación tiene UPDATE ni DELETE.
REVOKE UPDATE, DELETE ON {{IMMUTABLE}} FROM gestia_app, gestia_platform;
-- AUD-5: la cabeza de la cadena solo la mueve el trigger; manifiestos de archivo y verificaciones no se
-- reescriben desde la aplicación (el borrado vencido del archivo lo hace la tarea programada sin rol de aplicación).
REVOKE INSERT, UPDATE, DELETE ON audit_chain_heads FROM gestia_app;
REVOKE UPDATE, DELETE ON audit_archives, audit_integrity_checks FROM gestia_app;

-- Tablas de registro particionadas por mes (decisión A-06): se crean el mes en curso y los tres
-- siguientes. Las particiones no tienen privilegios propios: solo se accede por la tabla madre.
REVOKE ALL ON FUNCTION ensure_monthly_partitions(regclass, date, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ensure_monthly_partitions(regclass, date, integer) TO gestia_platform;
DO $$
DECLARE
  t text;
  p record;
BEGIN
  FOREACH t IN ARRAY ARRAY[{{PARTITIONED}}] LOOP
    PERFORM ensure_monthly_partitions(t::regclass, current_date, 4);
  END LOOP;
  FOR p IN SELECT c.relname FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relispartition AND c.relkind = 'r' LOOP
    EXECUTE format('REVOKE ALL ON %I FROM PUBLIC, gestia_app, gestia_platform', p.relname);
  END LOOP;
END
$$;

-- ORG-4 · RN-ORG-06 · Las líneas de una distribución por centros de costo suman exactamente 100 %.
-- Se comprueba al confirmar la transacción (diferida) para poder insertar las líneas una a una.
CREATE FUNCTION check_cost_distribution_total() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  dist uuid := COALESCE(NEW.employment_cost_distribution_id, OLD.employment_cost_distribution_id);
  total numeric;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM employment_cost_distributions WHERE id = dist) THEN
    RETURN NULL;
  END IF;
  SELECT COALESCE(sum(percentage), 0) INTO total FROM employment_cost_distribution_lines WHERE employment_cost_distribution_id = dist;
  IF total <> 100 THEN
    RAISE EXCEPTION 'La distribución por centros de costo % suma % %%; debe sumar 100 %%.', dist, total USING ERRCODE = 'AY006';
  END IF;
  RETURN NULL;
END
$$;
CREATE CONSTRAINT TRIGGER trg_cost_distribution_lines_total AFTER INSERT OR UPDATE OR DELETE ON employment_cost_distribution_lines
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_cost_distribution_total();

CREATE FUNCTION check_cost_distribution_has_lines() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM employment_cost_distributions WHERE id = NEW.id) THEN
    RETURN NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM employment_cost_distribution_lines WHERE employment_cost_distribution_id = NEW.id) THEN
    RAISE EXCEPTION 'La distribución por centros de costo % no tiene líneas; debe sumar 100 %%.', NEW.id USING ERRCODE = 'AY006';
  END IF;
  RETURN NULL;
END
$$;
CREATE CONSTRAINT TRIGGER trg_cost_distributions_lines AFTER INSERT ON employment_cost_distributions
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_cost_distribution_has_lines();

-- POS-1 · RN-POS-02 · Una posición admite tantos ocupantes a la vez como cupos tenga (seats; 1 si no es
-- compartida). Se cuentan las asignaciones de la posición que se traslapan con la nueva (SQLSTATE AY007).
CREATE FUNCTION check_position_seats() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  cupos integer;
  usados integer;
BEGIN
  IF NEW.position_id IS NULL THEN
    RETURN NEW;
  END IF;
  SELECT seats INTO cupos FROM positions WHERE tenant_id = NEW.tenant_id AND id = NEW.position_id;
  SELECT count(*) INTO usados FROM employment_assignments a
   WHERE a.tenant_id = NEW.tenant_id AND a.position_id = NEW.position_id AND a.id <> NEW.id
     AND a.deleted_at IS NULL
     AND daterange(a.valid_from, a.valid_to, '[]') && daterange(NEW.valid_from, NEW.valid_to, '[]');
  IF usados >= COALESCE(cupos, 1) THEN
    RAISE EXCEPTION 'La posición % ya tiene % ocupante(s) en esas fechas y solo admite %.', NEW.position_id, usados, COALESCE(cupos, 1) USING ERRCODE = 'AY007';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER trg_employment_assignments_position_seats BEFORE INSERT OR UPDATE OF position_id, valid_from, valid_to ON employment_assignments
  FOR EACH ROW EXECUTE FUNCTION check_position_seats();

-- IMP-2 · Regla 2: la historia laboral no se borra físicamente. Deshacer una importación lleva sus tramos a la
-- papelera (deleted_at); para el rol de aplicación esas filas dejan de existir en cualquier consulta (as_of,
-- cupos, alcance, equipo), y la exclusión de solapes ya las ignora. Solo esta función las marca: el rol de
-- aplicación no podría actualizar una fila que la política de lectura deja de ver.
CREATE POLICY hide_deleted ON employment_assignments AS RESTRICTIVE FOR SELECT TO gestia_app USING (deleted_at IS NULL);
CREATE POLICY hide_deleted ON employment_compensations AS RESTRICTIVE FOR SELECT TO gestia_app USING (deleted_at IS NULL);
CREATE POLICY hide_deleted ON employment_labor_regimes AS RESTRICTIVE FOR SELECT TO gestia_app USING (deleted_at IS NULL);
CREATE OR REPLACE FUNCTION trash_labor_history_row(p_table text, p_id uuid) RETURNS boolean
  LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
  IF p_table NOT IN ('employment_assignments', 'employment_compensations', 'employment_labor_regimes') THEN
    RAISE EXCEPTION 'tabla no admitida: %', p_table;
  END IF;
  EXECUTE format('UPDATE %I SET deleted_at = now() WHERE id = $1 AND tenant_id = app_tenant_id() AND deleted_at IS NULL', p_table) USING p_id;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n = 1;
END
$$;
REVOKE ALL ON FUNCTION trash_labor_history_row(text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION trash_labor_history_row(text, uuid) TO gestia_app;
-- AYU-6 · El administrador de una organización escribe al soporte de GestiaHR desde la ayuda. support_tickets es
-- de la plataforma (el rol de aplicación no la toca): estas funciones abren la incidencia y leen su estado solo
-- para la organización activa.
CREATE OR REPLACE FUNCTION open_support_ticket(p_subject text, p_body text, p_opened_by_email text) RETURNS uuid
  LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t uuid := app_tenant_id(); v uuid;
BEGIN
  IF t IS NULL THEN
    RAISE EXCEPTION 'sin organización activa';
  END IF;
  INSERT INTO support_tickets (tenant_id, opened_by_email, subject, body) VALUES (t, p_opened_by_email, left(p_subject, 200), p_body) RETURNING id INTO v;
  RETURN v;
END
$$;
REVOKE ALL ON FUNCTION open_support_ticket(text, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION open_support_ticket(text, text, text) TO gestia_app;
CREATE OR REPLACE FUNCTION support_ticket_status(p_id uuid) RETURNS text
  LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT status FROM support_tickets WHERE id = p_id AND tenant_id = app_tenant_id()
$$;
REVOKE ALL ON FUNCTION support_ticket_status(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION support_ticket_status(uuid) TO gestia_app;
