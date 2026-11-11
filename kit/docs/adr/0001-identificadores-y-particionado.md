# 0001 · Identificadores UUID v7 y particionado mensual de los registros

- **Estado**: Aceptada (aplicada desde la v1.6)
- **Fecha**: 2026-11-05
- **Decisión de origen**: A-03 y A-06 en `../13-decisiones-abiertas.md`
- **Quién decide**: área de desarrollo

## Contexto

La v1.5 recomendaba UUID v7 y particionar los registros grandes desde el inicio, pero el esquema usaba `gen_random_uuid()` (UUID v4, sin orden temporal) y ninguna tabla particionada. Cambiar el tipo de clave o particionar con datos en producción es caro.

## Opciones consideradas

1. **UUID v7 en todas las claves y particionado mensual de los registros sin claves foráneas entrantes.** Mantiene la localidad de índices y permite retener por partición. Exige que la clave primaria de una tabla particionada incluya la fecha.
2. **UUID v4 y sin particionar.** Más simple hoy; índices dispersos y retención por `DELETE` masivo, incompatible con tablas inalterables.

## Decisión

Opción 1. Todas las claves primarias usan `DEFAULT uuid_v7()`; la aplicación también puede generarlas (Laravel 12 `HasUuids` produce v7). Se particionan por mes sobre `occurred_at`: `audit_events`, `data_access_logs`, `activity_events`, `login_attempts`, `api_request_logs` y `platform_audit_events`.

## Consecuencias

- La clave primaria de esas seis tablas es (`id`, `occurred_at`) y su restricción única por organización es (`tenant_id`, `id`, `occurred_at`). Ninguna otra tabla las referencia.
- `ensure_monthly_partitions(tabla, desde, meses)` crea los meses siguientes; un trabajo programado de la plataforma la ejecuta cada mes con tres meses por delante. La partición `_default` solo es respaldo y debe quedar vacía.
- Las particiones tienen RLS forzado y la política `tenant_isolation`, y no tienen privilegios propios: solo se accede por la tabla madre.
- La retención descarta particiones completas (`DETACH PARTITION` + `DROP TABLE`) con el rol dueño; nunca `DELETE`.
- `outbox_events` y `attendance_punches` no se particionan por ahora porque otras tablas las referencian. Se revisa con las pruebas de carga (QA-11).
- En PostgreSQL 18, `uuid_v7()` puede reemplazarse por la función nativa `uuidv7()`.

## Comprobación

Bloque 9 de `kit/database/pruebas_aislamiento.sql`: versión y orden de `uuid_v7()`, DEFAULT en todas las claves, seis tablas particionadas, particiones con RLS y sin acceso directo de `gestia_app`, y aislamiento de una fila de auditoría en la partición del mes.
