-- ====================================================================================================
-- Ayni HR · Modelo de datos completo (fases 0 a 9)
-- PostgreSQL 17 (carga también en PostgreSQL 16). Cargar en una base vacía con un rol propietario:
--   psql -v ON_ERROR_STOP=1 -d ayni -f schema.sql
--
-- Convenciones
--   · Tablas y columnas en inglés, snake_case, tablas en plural. Comentarios en español.
--   · Multiempresa en esquema compartido: toda tabla de organización lleva tenant_id NOT NULL,
--     UNIQUE (tenant_id, id), RLS activado y forzado, y claves foráneas compuestas
--     (tenant_id, x_id) → (tenant_id, id): una fila no puede referenciar a otra organización.
--   · La aplicación fija la organización por transacción:  SET LOCAL app.tenant_id = '<uuid>';
--     Sin ese valor, las políticas no devuelven ninguna fila (no lanzan error).
--   · Tablas de plataforma (planes, organizaciones, catálogos globales) sin tenant_id ni RLS.
--   · Enumerados como text + CHECK; PK uuid con gen_random_uuid(); fechas con timestamptz;
--     montos numeric(14,2) con columna de moneda (char(3), ISO 4217).
--   · Historia con vigencia: valid_from / valid_to (ambas inclusive; valid_to NULL = vigente) y
--     restricción de exclusión que impide solapamientos (btree_gist).
--   · «-- SENSIBLE» marca columnas que exigen permiso por campo y registro de acceso.
--     Las columnas *_enc se cifran en la aplicación; las *_hash son huellas para búsqueda exacta.
--   · lock_version: concurrencia optimista. La aplicación actualiza con
--     WHERE id = ? AND lock_version = ?; el trigger incrementa el valor.
--   · deleted_at: borrado lógico (papelera). Los índices únicos de esas tablas son parciales.
--   · Las colas, la caché y los logs técnicos no viven en esta base (Redis / Horizon / agregador de logs).
-- ====================================================================================================

SET client_min_messages = warning;
SET check_function_bodies = on;

CREATE EXTENSION IF NOT EXISTS btree_gist;

-- ----------------------------------------------------------------------------------------------------
-- Roles de base de datos
--   ayni_app       rol de la aplicación de las organizaciones. Sin BYPASSRLS: siempre sujeto a RLS.
--   ayni_platform  rol de la consola SaaS y de las tareas de plataforma. Opera entre organizaciones.
-- Son roles sin inicio de sesión: los usuarios de conexión reales se crean aparte y heredan de ellos.
-- ----------------------------------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ayni_app') THEN
    CREATE ROLE ayni_app NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ayni_platform') THEN
    CREATE ROLE ayni_platform NOLOGIN NOSUPERUSER BYPASSRLS NOCREATEDB NOCREATEROLE;
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
