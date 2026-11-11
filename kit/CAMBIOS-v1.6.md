# GestiaHR · Cambios de la versión 1.6

Versión 1.6 · 04/11/2026. Corrige las inconsistencias y faltantes del análisis del kit v1.5. No cambia la arquitectura ni el contrato de la API.

## Estructura y referencias

- **Estructura del repositorio**: el zip se descomprime en la raíz. `CLAUDE.md` y `.claude/commands/` quedan en la raíz y el kit en `kit/`. Todas las rutas de `CLAUDE.md`, los comandos y `PROMPTS.md` llevan el prefijo `kit/`, y los generadores ya asumían esa estructura.
- **Referencias rotas**: las citas a `docs/research.md` (21), `SPEC.md` (63) y `prompt-original.md` (3) apuntan ahora a `docs/00-analisis-de-competencia.md` §5, `docs/00-especificacion-maestra.md` y `docs/00-pedido-original.md`. `generar_backlog.py` leía `SPEC.md` y `prompt-original.md` fuera del kit y no podía ejecutarse; ahora corre desde el kit.
- **Nombres de tabla**: `docs/09` usaba `integration_connections` e `inbound_events`; ahora usa `integrations` e `inbox_events`, como el esquema. Los nombres de 02, 03, 12 y 15 se cotejaron con el esquema.
- **`docs/adr/`**: nuevo, con plantilla, índice de las once decisiones que bloquean la fase 0 y dos propuestas: 0001 (UUID v7 y particionado, A-03 y A-06) y 0002 (códigos de permiso, D-29).
- Se quitaron las carpetas `__pycache__`.

## Esquema (`tools/esquema/`, regenerado)

- **UUID v7 (A-03)**: nueva función `uuid_v7()`; es el DEFAULT de todas las claves primarias (antes `gen_random_uuid()`, versión 4).
- **Particionado mensual (A-06)**: `audit_events`, `data_access_logs`, `activity_events`, `login_attempts`, `api_request_logs` y `platform_audit_events` se particionan por mes sobre `occurred_at`. Nueva función `ensure_monthly_partitions()`; cada partición lleva RLS forzado y política, y no tiene privilegios directos. `outbox_events` y `attendance_punches` no se particionan porque otras tablas las referencian (ver `docs/13` A-06).
- **Pruebas**: `pruebas_aislamiento.sql` suma el bloque 9 (UUID v7, particiones con RLS, sin acceso directo y aislamiento de una fila particionada) y ajusta los bloques 0.2 y 3 para tablas particionadas. Todo pasa en PostgreSQL 16.15.
- `gen_docs.py` no cuenta particiones como tablas: los conteos de `docs/04` no cambian salvo funciones (18 → 20).
- Las 28 claves foráneas «sin índice propio» señaladas en el análisis van a catálogos de plataforma y ya tienen índice (`tenant_id`, columna): no se cambiaron.

## Parámetros legales

- **Historial 2025**: UIT 2025 = S/ 5 350 (DS 260-2024-EF) y RMV S/ 1 130 desde el 1-1-2025 (DS 006-2024-TR), ambas cotejadas con la norma. 27 parámetros que no cambiaron entre 2025 y 2026 (aportes, escala y divisores de quinta, gratificación, CTS, utilidades, MYPE) extienden su vigencia al 1-1-2025 con una nota.
- **`docs/19-parametros-legales-por-validar.md`** (nuevo, generado por `gen_pendientes.py`): los 39 parámetros sin valor verificado, 32 de ellos del primer corte comercial, con columna para que el especialista anote valor y norma; y los 90 con fecha de inicio por confirmar. El análisis de la v1.5 hablaba de 47 «sin valor»: 8 de ellos sí tienen valor en formato JSON (escalas y meses).

## Backlog (`tools/fuente/`, regenerado)

- **Primer corte comercial**: etiqueta `mvp` en 224 actividades (plataforma, Core HR, VAC, AUS y nómina Perú del régimen general y MYPE, más QA-1, QA-2 y QA-17). El CSV pasa a tres columnas `Labels`. Nueva verificación: ninguna actividad mvp depende de algo fuera del MVP.
- Para que el MVP no exija asistencia, horas extra ni préstamos: AUS-6, NOM-5, BSO-3 y LIQ-1 dejan de depender de ASI-8, BHO-2 y PRE-3, y leen esos datos por puertos con respaldo en las incidencias del periodo. ASI-8 ahora depende de AUS-6 y PRE-3 de LIQ-1, para que la integración se construya cuando lleguen esos módulos.
- **Nuevas**: PER-12 «Importación de acumulados de planilla del ejercicio» (QA-14 depende de ella) y QA-17 «Salida a producción del primer corte comercial».
- **Ampliadas**: SAS-11 emite comprobante electrónico ante SUNAT por un adaptador OSE/PSE, con el IGV como parámetro; PLT-3 corre el esquema en PostgreSQL 17 y valida los mermaid; SEG-8 usa códigos de permiso en inglés.
- Totales: 70 épicas, 337 historias, 145 tareas (482 actividades). `GestiaHR_backlog_jira.csv` en la raíz es el mismo `kit/backlog/backlog.csv`.

## Documentos

- `docs/01` §5.1: definición del primer corte comercial y excepción a «2 antes de 3».
- `docs/13`: estado en v1.6 de A-03, A-06 y D-29; D-04 suma comprobantes electrónicos y medios de pago locales; D-15 enlaza `docs/19`.
- `README.md`, `CLAUDE.md` (orden MVP, archivos generados, ADR) y `tools/esquema/LEEME.md` actualizados.

## Pendiente

- Probar el esquema en PostgreSQL 17 y renderizar los 70 diagramas mermaid (quedan como criterio de PLT-3).
- Cerrar formalmente las once decisiones de la fase 0 y validar los 32 parámetros del MVP con un especialista laboral.
- Expandir en el catálogo el permiso dinámico `settings.{group}.*` cuando se fijen los grupos de configuración.
