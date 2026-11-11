-- ====================================================================================================
-- GestiaHR · Modelo de datos completo (fases 0 a 9)
-- PostgreSQL 17 (carga también en PostgreSQL 16). Cargar en una base vacía con un rol propietario:
--   psql -v ON_ERROR_STOP=1 -d gestiahr -f schema.sql
--
-- Convenciones
--   · Tablas y columnas en inglés, snake_case, tablas en plural. Comentarios en español.
--   · Multiempresa en esquema compartido: toda tabla de organización lleva tenant_id NOT NULL,
--     UNIQUE (tenant_id, id), RLS activado y forzado, y claves foráneas compuestas
--     (tenant_id, x_id) → (tenant_id, id): una fila no puede referenciar a otra organización.
--   · La aplicación fija la organización por transacción:  SET LOCAL app.tenant_id = '<uuid>';
--     Sin ese valor, las políticas no devuelven ninguna fila (no lanzan error).
--   · Tablas de plataforma (planes, organizaciones, catálogos globales) sin tenant_id ni RLS.
--   · Enumerados como text + CHECK; PK uuid v7 (uuid_v7(), ordenable por tiempo, decisión A-03); fechas con timestamptz;
--     montos numeric(14,2) con columna de moneda (char(3), ISO 4217).
--   · Historia con vigencia: valid_from / valid_to (ambas inclusive; valid_to NULL = vigente) y
--     restricción de exclusión que impide solapamientos (btree_gist).
--   · «-- SENSIBLE» marca columnas que exigen permiso por campo y registro de acceso.
--     Las columnas *_enc se cifran en la aplicación; las *_hash son huellas para búsqueda exacta.
--   · lock_version: concurrencia optimista. La aplicación actualiza con
--     WHERE id = ? AND lock_version = ?; el trigger incrementa el valor.
--   · deleted_at: borrado lógico (papelera). Los índices únicos de esas tablas son parciales.
--   · Tablas de registro de gran volumen (auditoría, accesos, actividad, intentos de ingreso, uso de API,
--     auditoría de plataforma) particionadas por mes sobre occurred_at (decisión A-06). Su PK es
--     (id, occurred_at). ensure_monthly_partitions() crea los meses siguientes; la retención descarta
--     particiones completas. Cada tabla tiene además una partición DEFAULT que no debe recibir filas.
--   · Las colas, la caché y los logs técnicos no viven en esta base (Redis / Horizon / agregador de logs).
-- ====================================================================================================

SET client_min_messages = warning;
SET check_function_bodies = on;

CREATE EXTENSION IF NOT EXISTS btree_gist;

-- ----------------------------------------------------------------------------------------------------
-- Roles de base de datos
--   gestia_app       rol de la aplicación de las organizaciones. Sin BYPASSRLS: siempre sujeto a RLS.
--   gestia_platform  rol de la consola SaaS y de las tareas de plataforma. Opera entre organizaciones.
-- Son roles sin inicio de sesión: los usuarios de conexión reales se crean aparte y heredan de ellos.
-- ----------------------------------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
    CREATE ROLE gestia_app NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
    CREATE ROLE gestia_platform NOLOGIN NOSUPERUSER BYPASSRLS NOCREATEDB NOCREATEROLE;
  END IF;
END
$$;

-- ----------------------------------------------------------------------------------------------------
-- Funciones de apoyo
-- ----------------------------------------------------------------------------------------------------

-- Organización activa de la sesión; NULL si no se fijó.
CREATE FUNCTION app_tenant_id() RETURNS uuid
LANGUAGE sql STABLE
AS $$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$;
COMMENT ON FUNCTION app_tenant_id() IS 'Organización activa de la sesión (app.tenant_id); NULL si no se fijó.';

-- UUID versión 7 (RFC 9562): 48 bits de milisegundos Unix + bits aleatorios. Ordenable por tiempo,
-- mantiene localidad en los índices y no expone volumen. La aplicación puede generarlo también
-- (Laravel 12 HasUuids genera v7); este DEFAULT cubre las inserciones hechas desde SQL.
-- En PostgreSQL 18 puede reemplazarse por la función nativa uuidv7().
CREATE FUNCTION uuid_v7() RETURNS uuid
LANGUAGE sql VOLATILE PARALLEL SAFE
AS $$
  SELECT encode(
    set_bit(set_bit(
      overlay(uuid_send(gen_random_uuid())
              placing substring(int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3)
              FROM 1 FOR 6),
      52, 1), 53, 1),
    'hex')::uuid
$$;
COMMENT ON FUNCTION uuid_v7() IS 'UUID versión 7 ordenable por tiempo (decisión A-03). DEFAULT de todas las claves primarias.';

CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END
$$;

CREATE FUNCTION set_updated_at_and_lock() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at := now();
  NEW.lock_version := OLD.lock_version + 1;
  RETURN NEW;
END
$$;

CREATE FUNCTION bump_lock_version() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.lock_version := OLD.lock_version + 1;
  RETURN NEW;
END
$$;

-- Tablas de solo inserción: cualquier UPDATE, DELETE o TRUNCATE falla (SQLSTATE AY001).
CREATE FUNCTION reject_mutation() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  RAISE EXCEPTION 'La tabla % es de solo inserción: % no está permitido. Una corrección se registra como una fila nueva.', TG_TABLE_NAME, TG_OP
    USING ERRCODE = 'AY001';
END
$$;
COMMENT ON FUNCTION reject_mutation() IS 'Rechaza UPDATE, DELETE y TRUNCATE en tablas inalterables (auditoría, marcaciones, firmas, bitácora de IA, libros de saldos).';

-- ----------------------------------------------------------------------------------------------------
-- Particiones mensuales (decisión A-06)
-- Crea las particiones de p_months meses a partir del mes de p_from (UTC). Es idempotente.
-- Cada partición nueva hereda la política de aislamiento y no recibe privilegios propios: solo se accede
-- a ella a través de la tabla madre, donde rigen RLS y los privilegios. La consola de plataforma la
-- programa (por ejemplo, el día 1 de cada mes con 3 meses por delante). Para retener: DETACH + DROP
-- de las particiones vencidas, nunca DELETE.
-- ----------------------------------------------------------------------------------------------------
CREATE FUNCTION ensure_monthly_partitions(p_table regclass, p_from date, p_months integer DEFAULT 3) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE
  v_start date := date_trunc('month', p_from)::date;
  v_name text;
  v_created integer := 0;
  v_has_tenant boolean;
  v_parent text := (SELECT relname FROM pg_class WHERE oid = p_table);
BEGIN
  IF (SELECT relkind FROM pg_class WHERE oid = p_table) <> 'p' THEN
    RAISE EXCEPTION '% no es una tabla particionada', p_table;
  END IF;
  v_has_tenant := EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = p_table AND attname = 'tenant_id' AND NOT attisdropped);
  FOR i IN 0 .. p_months - 1 LOOP
    v_name := v_parent || '_p' || to_char(v_start + make_interval(months => i), 'YYYYMM');
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = v_name AND relnamespace = 'public'::regnamespace) THEN
      EXECUTE format('CREATE TABLE %I PARTITION OF %s FOR VALUES FROM (%L) TO (%L)', v_name, p_table,
                     ((v_start + make_interval(months => i))::timestamp AT TIME ZONE 'UTC'),
                     ((v_start + make_interval(months => i + 1))::timestamp AT TIME ZONE 'UTC'));
      IF v_has_tenant THEN
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', v_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', v_name);
        EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id())', v_name);
      END IF;
      EXECUTE format('REVOKE ALL ON %I FROM PUBLIC, gestia_app, gestia_platform', v_name);
      v_created := v_created + 1;
    END IF;
  END LOOP;
  RETURN v_created;
END
$$;
COMMENT ON FUNCTION ensure_monthly_partitions(regclass, date, integer) IS 'Crea particiones mensuales (UTC) de una tabla de registro particionada, con RLS y sin privilegios directos. Idempotente.';
