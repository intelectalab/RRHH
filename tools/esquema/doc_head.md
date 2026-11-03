# 04 · Modelo de datos

Ayni HR · kit de desarrollo. Describe `database/schema.sql`: principios, un diagrama por dominio y, por cada tabla, su propósito, columnas clave, relaciones y reglas. El esquema es la referencia; si este documento y `schema.sql` discrepan, manda `schema.sql`.

Archivos relacionados:

| Archivo | Contenido |
|---|---|
| `database/schema.sql` | Esquema completo de las fases 0 a 9, con catálogos de plataforma y la función `seed_tenant_defaults` |
| `database/pruebas_aislamiento.sql` | Pruebas con aserciones de aislamiento, claves foráneas cruzadas, registros inalterables, vigencias y anonimato |
| `database/catalogos/permisos_y_roles.json` | Permisos y matriz de los 11 roles de sistema (misma información que los `INSERT` del esquema) |
| `database/catalogos/modulos.json` | Módulos comerciales y dependencias |
| `database/catalogos/parametros.json` | Parámetros configurables del producto |
| `database/catalogos/parametros_legales_pe.json` | Parámetros legales de Perú con vigencia y marca de verificación |
| `database/catalogos/conceptos_pe.json` | Conceptos de planilla de Perú con afectaciones (por validar) |

## 1. El esquema en números

Conteos obtenidos del catálogo de PostgreSQL ({{version}}) después de cargar `schema.sql` en una base vacía; no son estimaciones.

| Medida | Cantidad |
|---|---:|
| Tablas | {{tablas}} |
| Tablas de organización (con `tenant_id`, RLS activado y forzado, política `tenant_isolation`) | {{tablas_rls}} |
| Tablas de plataforma (sin RLS de organización) | {{tablas_plataforma}} |
| Políticas RLS | {{politicas}} |
| Claves foráneas | {{fks}} |
| — compuestas `(tenant_id, x_id)` → `(tenant_id, id)` entre tablas de organización | {{fks_compuestas}} |
| — simples `tenant_id` → `tenants(id)` | {{fks_a_tenants}} |
| — resto (hacia catálogos y tablas de plataforma) | {{fks_resto}} |
| Índices (incluye los de claves primarias, únicas y exclusiones) | {{indices}} |
| — creados con `CREATE INDEX` | {{indices_explicitos}} |
| Triggers | {{triggers}} |
| Restricciones de exclusión (vigencias sin solapamiento) | {{exclusiones}} |
| Restricciones `CHECK` | {{checks}} |
| Restricciones `UNIQUE` | {{unicas}} |
| Columnas | {{columnas}} |
| Columnas marcadas `-- SENSIBLE` | {{sens_cols}} |
| — de ellas, cifradas en la aplicación (`_enc`) | {{enc_cols}} |
| Tablas con vigencia (`valid_from` / `valid_to`) | {{tablas_vigencia}} |
| Tablas inalterables (solo inserción, por trigger) | {{tablas_inmutables}} |
| Tablas con borrado lógico (`deleted_at`) | {{tablas_soft}} |
| Tablas con concurrencia optimista (`lock_version`) | {{tablas_lock}} |
| Funciones | {{funciones}} |
| Vistas | {{vistas}} |
| Permisos en el catálogo | {{permisos}} |
| — de campo / marcados sensibles | {{permisos_campo}} / {{permisos_sensibles}} |
| Roles de sistema | {{roles_sistema}} |
| Módulos comerciales | {{modulos}} |

Nota: el resto de claves foráneas es la diferencia entre el total y las dos filas anteriores; son referencias a tablas de plataforma (por `id` o por `code`) y las internas de la plataforma. `pruebas_aislamiento.sql` comprueba que **toda** clave foránea entre dos tablas de organización es compuesta.

Tablas por épica:

| Fase | Épica | Título | Tablas |
|---:|---|---|---:|
{{tabla_epicas}}

## 2. Principios

### 2.1 Multiempresa en esquema compartido

- Una organización cliente es una fila de `tenants`. Toda tabla de organización lleva `tenant_id uuid NOT NULL`.
- **RLS activado y forzado** en cada tabla de organización (`FORCE ROW LEVEL SECURITY`: también el dueño de la tabla queda sujeto). La política es la misma en todas:

```sql
CREATE POLICY tenant_isolation ON <tabla>
  USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
```

- La aplicación fija la organización al abrir cada transacción: `SET LOCAL app.tenant_id = '<uuid>'`. Sin ese valor (o con cadena vacía) la política **no devuelve filas y no lanza error**; por eso se usa `current_setting(..., true)` con `NULLIF`.
- **Claves foráneas compuestas.** Cada tabla de organización tiene `UNIQUE (tenant_id, id)` y las referencias son `FOREIGN KEY (tenant_id, x_id) REFERENCES x (tenant_id, id)`. Una fila no puede apuntar a otra organización aunque falle el código o se use un rol que ignora RLS. Como efecto útil, todos los índices de claves foráneas empiezan por `tenant_id`.
- **Índice por organización.** No hay un índice suelto sobre `tenant_id`: el de `UNIQUE (tenant_id, id)` y los de claves foráneas `(tenant_id, x_id)` ya lo encabezan.
- **Tablas de plataforma** sin `tenant_id` propio ni RLS: planes, organizaciones, suscripciones, módulos, banderas, usuarios y auditoría de plataforma, paquetes de país, parámetros legales globales, catálogos de permisos y regímenes. Algunas guardan un `tenant_id` como dato (suscripciones, facturas, dominios), sin política.
- **Roles de base de datos.** `ayni_app` (sin `BYPASSRLS`) para la API y los trabajadores de cola; `ayni_platform` (`BYPASSRLS`) para la consola SaaS. Son roles sin inicio de sesión: los usuarios de conexión heredan de ellos. `ayni_app` solo lee los catálogos de plataforma y no tiene acceso a `tenants`, planes, suscripciones ni usuarios de plataforma: ve su organización por la vista `current_tenant` y resuelve el dominio con `resolve_tenant_id()`.
- Un superusuario y los roles con `BYPASSRLS` no pasan por RLS: las migraciones corren con el rol dueño y la aplicación nunca se conecta con él.

### 2.2 Persona, usuario y relación laboral (C-01, C-02)

- `people` es la persona; `users` es el acceso (puede no tener persona, y el correo no es obligatorio: los identificadores de acceso están en `user_identities`); `employments` es la relación laboral con **una** razón social (`legal_entities`). Un reingreso es otra fila de `employments` para la misma persona.
- `job_profiles` es el puesto de catálogo; `positions` es la plaza presupuestada. Headcount, vacantes, organigrama y sucesión trabajan sobre posiciones.
- Sueldo, jefe, puesto, unidad, sede y saldos tienen una sola fuente (C-11): las tablas de vigencia y los libros de movimientos. Los demás módulos guardan referencias, no copias; las únicas copias son fotografías deliberadas de un cálculo cerrado (`payroll_run_employments.context_snapshot`, `payslips.content`).

### 2.3 Historia con vigencia (C-03)

- `valid_from` y `valid_to` son fechas **inclusive**; `valid_to` nulo significa «vigente sin fecha de fin».
- Las tablas de vigencia llevan una restricción de exclusión (`btree_gist`) que impide dos filas solapadas para la misma clave:

```sql
EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =,
                    daterange(valid_from, valid_to, '[]') WITH &&) DEFERRABLE INITIALLY IMMEDIATE
```

- Cambiar un dato es cerrar la fila vigente (poner `valid_to`) e insertar otra; un cambio con fecha futura es una fila que empieza más adelante. La restricción es diferible para poder hacer ambas cosas en una transacción.
- Consulta a una fecha: `WHERE valid_from <= :fecha AND (valid_to IS NULL OR valid_to >= :fecha)`.
- `personnel_actions` agrupa los registros que un mismo movimiento genera (promoción = nueva asignación + nueva compensación).

| Tabla con vigencia | Clave sin solapamiento | Exclusión |
|---|---|---|
{{tabla_vigencias}}

### 2.4 Registros inalterables y auditoría (C-07)

- Tablas de solo inserción, protegidas por trigger (`reject_mutation`, SQLSTATE `AY001`) contra `UPDATE`, `DELETE` y `TRUNCATE`, y además sin esos privilegios para `ayni_app` y `ayni_platform`: {{lista_inmutables}}.
- Los saldos (vacaciones, bolsa de horas) son libros de movimientos: el saldo es la suma y un error se corrige con un contraasiento.
- Una marcación (`attendance_punches`) no se edita: la corrección es otra fila con `entry_type = 'correction'`, `corrects_punch_id` y `correction_reason`.
- Boletas y corridas de planilla se pueden modificar mientras son borrador; cerradas (`closed_at`), cualquier cambio falla con `AY002`.
- Tres almacenes distintos: `audit_events` (negocio, con antes y después), `activity_events` (actividad del usuario) y los logs técnicos, que no viven en esta base. `data_access_logs` registra cada acceso a un dato sensible.

Códigos de error propios del esquema:

| SQLSTATE | Significado |
|---|---|
| `AY001` | Intento de modificar, borrar o truncar una tabla de solo inserción |
| `AY002` | Intento de modificar un registro cerrado (boleta o corrida de planilla) |
| `AY003` | Intento de romper el anonimato (identidad en denuncia o encuesta anónima) |

### 2.5 Datos sensibles

- `-- SENSIBLE` en `schema.sql` y **(S)** en este documento marcan columnas que exigen permiso por campo y cuyo acceso se registra en `data_access_logs`.
- Las columnas `_enc` guardan texto cifrado por la aplicación (la base nunca ve el dato en claro). Cuando hay que buscar por valor exacto existe una columna `_hash` con una huella con clave (documento de identidad, teléfono, RUC del locador).
- Los montos de sueldo y de planilla se marcan sensibles pero **no** van cifrados por campo, porque la planilla, las provisiones y la analítica necesitan operar con ellos en SQL; los protege el permiso por campo, RLS y el cifrado del almacenamiento. Es una decisión a confirmar (sección final).
- Tablas completas tratadas como sensibles: {{lista_sensibles}}.
- **Denuncias (C-06).** `complaints` no tiene columnas de usuario, persona, IP, agente de navegador ni marcas de hora: solo fecha, huellas del código de seguimiento y contenido cifrado. La identidad, cuando la denuncia es identificada, vive cifrada en `complaint_identities`, sin clave foránea a persona, y un trigger impide crearla en una denuncia anónima.
- **Encuestas (C-06).** `survey_responses` y `survey_answers` no tienen clave foránea a persona ni marcas de hora. Quién respondió se sabe por `survey_participations`, que no enlaza con la respuesta. El umbral mínimo por segmento está en `surveys.min_responses_per_segment` y se aplica al mostrar resultados.

### 2.6 Convenciones de columnas

| Elemento | Convención |
|---|---|
| Clave primaria | `id uuid DEFAULT gen_random_uuid()` |
| Estados y tipos cerrados | `text` + `CHECK (col IN (...))`, con valores en inglés; nunca `ENUM` de PostgreSQL. Los de `status` están conciliados con `05-maquinas-de-estado.md` (sección final) |
| Fechas | `…_at` es `timestamptz`; `…_on` es `date`; las fechas laborales no llevan hora |
| Dinero | `numeric(14,2)` con una columna de moneda `char(3)` en la misma tabla |
| Booleanos | `is_…`, `has_…` |
| Marcas estándar | `created_at`, `updated_at` (trigger); `deleted_at` en lo que va a papelera; `lock_version` donde hay edición concurrente |
| Borrado lógico | Los índices únicos de esas tablas son parciales (`WHERE deleted_at IS NULL`): se puede reutilizar un código eliminado |
| Concurrencia optimista | La aplicación actualiza con `WHERE id = ? AND lock_version = ?`; el trigger suma 1. Cero filas afectadas = conflicto |
| Referencias polimórficas | `subject_type` + `subject_id` (o `entity_…`, `source_…`) sin clave foránea; solo en piezas transversales (workflows, notificaciones, adjuntos, auditoría, campos personalizados) |
| Campos personalizados | Definición en `custom_field_definitions`, valor `jsonb` en `custom_field_values` |
| Catálogos de plataforma con clave natural | Se referencian por `code` (`modules`, `permissions`, `labor_regimes`…) para que los datos sean legibles |

### 2.7 Reglas legales como datos (C-05)

- `legal_parameters` (plataforma): parámetro por país y, si corresponde, régimen, con `valid_from`/`valid_to` y exclusión de solapamiento. `legal_parameter_overrides` permite un valor propio de la organización o empresa (por ejemplo, la tasa de una póliza).
- `payroll_concepts` + `payroll_concept_versions` (fórmula, condiciones y afectaciones con vigencia). Las fórmulas referencian parámetros por código, nunca valores.
- `schema.sql` **no** carga parámetros legales ni conceptos: los instala el paquete de país desde `catalogos/parametros_legales_pe.json` y `catalogos/conceptos_pe.json`. En el primero, lo no verificado va con `"verificado": false` y valor nulo; el segundo debe validarlo un especialista antes de usarse.

### 2.8 Plataforma en los cimientos (C-04)

- `modules`, `plans`, `subscriptions` y `tenant_modules` condicionan qué puede usar una organización; `tenant_limits` y `tenant_usage_counters` aplican los límites; `feature_flags` con reglas y `tenant_feature_flags` liberan funciones de forma gradual.
- Workflows y aprobaciones (`workflow_*`), notificaciones, campos personalizados, outbox e idempotencia son transversales: los módulos de las fases 2 a 9 los usan, no los duplican. Las solicitudes de cada módulo guardan `workflow_instance_id`.
- `seed_tenant_defaults(tenant uuid)` copia a una organización nueva los roles de sistema y sus permisos desde `system_roles` / `system_role_permissions`, crea su política de seguridad y activa los módulos base. Es idempotente.

## 3. Cómo cargar y comprobar

```bash
createdb ayni
psql -v ON_ERROR_STOP=1 -d ayni -f database/schema.sql
psql -v ON_ERROR_STOP=1 -d ayni -f database/pruebas_aislamiento.sql   # termina en «TODAS LAS PRUEBAS PASARON»
```

El esquema usa la extensión `btree_gist` y crea los roles `ayni_app` y `ayni_platform` si no existen. Para el proyecto Laravel, este archivo es la referencia desde la que se escriben las migraciones por módulo; no se ejecuta en producción tal cual.

## 4. Cómo leer las fichas de tabla

- **Columnas clave** lista las columnas propias (sin `id`, `tenant_id`, marcas estándar ni claves foráneas). **(S)** = sensible.
- **Relaciones** lista las claves foráneas. Hacia tablas de organización son siempre compuestas con `tenant_id`; «(opcional)» = admite nulo. `tabla.code` indica referencia a un catálogo de plataforma por su código.
- **Reglas** resume aislamiento, vigencia, inalterabilidad, sensibilidad, unicidad y validaciones.

## 5. Tablas que no existen a propósito

- **Portal del manager (MSS):** no tiene tablas; es una vista con alcance «equipo» sobre asignaciones, aprobaciones, asistencia, vacaciones, objetivos y evaluaciones.
- **Colas, caché, sesiones del framework y logs técnicos:** viven en Redis y en el agregador de logs, no en este esquema.
- **Calendario de RRHH, bandeja de pendientes, búsqueda global, cumpleaños y aniversarios:** se derivan de otras tablas; solo los eventos propios se guardan (`calendar_events`).
- **Saldos:** no hay columnas de saldo de vacaciones ni de bolsa de horas; se calculan de los libros de movimientos.
