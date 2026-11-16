# 08 · Guía de la API

Contrato: `../api/openapi.yaml` (OpenAPI 3.0.3, generado por `../tools/gen_openapi.py`). Esta guía explica las reglas comunes; el YAML manda en rutas y esquemas. El archivo es grande: búscalo por ruta (`grep -n "/vacation-requests" api/openapi.yaml`).

## 1. Principios

- **API primero**: la web, la app móvil, WhatsApp, el servidor MCP y las integraciones usan la misma API.
- REST sobre HTTPS, JSON, `snake_case`, recursos en plural y en inglés.
- **Los estados cambian por acciones** (`POST /recurso/{id}/accion`), nunca por `PATCH` de `status`.
- Todo dato se resuelve en una **fecha de vigencia** (`as_of`).
- La ausencia de un campo significa «sin permiso para verlo»; `null` significa «sin valor».

## 2. Versionado

- Prefijo de ruta: `/api/v1`. Una versión mayor nueva (`/api/v2`) solo ante cambios incompatibles; ambas conviven durante el periodo de transición que defina el negocio (D-18).
- Compatibles (no cambian la versión): añadir rutas, campos opcionales de entrada, campos de salida, valores de enumeración en campos documentados como abiertos, eventos nuevos.
- Incompatibles: quitar o renombrar campos, cambiar tipos, volver obligatorio un campo, cambiar el significado de un estado.
- Los clientes deben ignorar campos desconocidos.
- Cabecera `Sunset` y `Deprecation` en rutas en retirada.
- Los eventos de webhook llevan su propia `version` por evento.

## 3. Autenticación

| Mecanismo | Para | Detalle |
|---|---|---|
| **Sesión web** | `apps/web` | Cookie `gestia_session` `HttpOnly`, `Secure`, `SameSite=Lax`; protección CSRF con cabecera `X-XSRF-TOKEN` en toda escritura. `POST /auth/login` admite correo **o** documento de identidad + clave; `POST /auth/otp` para acceso con código de un solo uso (personal sin correo, C-09); `POST /auth/mfa/verify` para el segundo factor |
| **SSO** | Organizaciones con proveedor de identidad | OIDC o SAML por adaptador; termina en sesión web |
| **Token personal** | App móvil, scripts de un usuario | `Authorization: Bearer …`; hereda los permisos y el alcance del usuario, recortados por scopes |
| **Token de integración** | Sistemas externos, MCP | Pertenece a la organización, ligado a un usuario de servicio con rol propio; scopes obligatorios; caducidad y rotación |
| **Token de dispositivo** | Kioskos y relojes | Solo puede crear marcaciones de su sede |
| **Público** | Portal de empleos, denuncias, firma por enlace | Sin autenticación; límite de solicitudes estricto; firma por enlace con token de un solo uso |

Scopes: `recurso:lectura|escritura` por dominio, en inglés: `employments:read`, `employments:write`, `time:read`, `time:write`, `payroll:read`, `recruiting:write`, `documents:read`, `webhooks:manage`, `mcp:use`… Un scope **recorta**, nunca amplía: el permiso efectivo es la intersección de scope, permisos del rol, alcance, permisos por campo y módulo contratado.

Los tokens se muestran una sola vez al crearlos, se guardan con hash y registran último uso. `GET /me` devuelve usuario, organización, razones sociales visibles, permisos efectivos, alcance, módulos y flags.

## 4. Envoltura y errores

Éxito, un recurso:

```json
{ "data": { "id": "01J…", "status": "pending", "days": "5.0" },
  "meta": { "available_actions": ["approve", "reject", "cancel"], "as_of": "2026-10-02" } }
```

Éxito, lista: `{ "data": [ … ], "meta": { "page": 1, "per_page": 25, "total": 312, "next_cursor": null } }`

Proceso largo: `202` con `{ "data": { "job_id": "…", "status": "queued", "progress_url": "…" } }`.

Error:

```json
{ "error": {
    "code": "invalid_transition",
    "message": "La solicitud ya fue aprobada; no se puede aprobar otra vez.",
    "request_id": "9f3c…",
    "details": [],
    "meta": { "current_status": "approved", "allowed_actions": ["cancel"] } } }
```

### Catálogo de códigos

| HTTP | `code` | Cuándo | `meta` / `details` |
|---|---|---|---|
| 400 | `bad_request` | JSON inválido, parámetro mal formado | — |
| 400 | `invalid_filter` | Filtro, orden o campo no permitido | `meta.allowed` |
| 401 | `unauthenticated` | Sin sesión o token inválido o caducado | — |
| 401 | `mfa_required` | Falta el segundo factor | `meta.methods` |
| 403 | `forbidden` | Falta el permiso | `meta.permission` |
| 403 | `scope_insufficient` | El token no tiene el scope | `meta.scope` |
| 403 | `module_not_enabled` | La organización no tiene el módulo | `meta.module` |
| 403 | `feature_not_enabled` | Feature flag apagado | `meta.feature` |
| 403 | `tenant_suspended` | Organización suspendida | — |
| 403 | `plan_limit_reached` | Se superaría un límite del plan | `meta.limit`, `meta.current`, `meta.max` |
| 404 | `not_found` | No existe, es de otra organización o está fuera de alcance | — |
| 409 | `invalid_transition` | La acción no procede en el estado actual | `meta.current_status`, `meta.allowed_actions` |
| 409 | `version_conflict` | `If-Match` no coincide | `meta.current_version` |
| 409 | `duplicate` | Viola una unicidad de negocio | `meta.fields` |
| 409 | `idempotency_conflict` | Misma clave con cuerpo distinto | — |
| 409 | `overlap` | Solape de vigencias o de fechas | `meta.conflicts_with` |
| 409 | `period_closed` | El periodo de planilla o asistencia está cerrado | `meta.period` |
| 409 | `in_use` | No se puede eliminar o archivar: tiene dependencias | `meta.references` |
| 422 | `validation_failed` | Datos inválidos | `details[] {field, rule, message}` |
| 422 | `business_rule_violated` | Regla de negocio (saldo insuficiente, tope de contrato, sin posición aprobada) | `meta.rule` |
| 422 | `approval_required` | La operación debe pasar por aprobación | `meta.approval_request_id` |
| 423 | `locked` | Recurso bloqueado por un proceso en curso (cálculo de planilla) | `meta.job_id` |
| 429 | `rate_limited` | Límite de solicitudes | cabecera `Retry-After` |
| 500 | `internal_error` | Error inesperado | solo `request_id` |
| 502 | `upstream_unavailable` | Proveedor externo no responde en una operación síncrona | `meta.connector` |
| 503 | `maintenance` | Mantenimiento | `Retry-After` |

Reglas:

- `message` viene traducido al idioma del usuario y es apto para mostrar.
- `rule` y `code` son estables; los clientes programan contra ellos, no contra `message`.
- 404 (no 403) cuando el registro existe pero está fuera de la organización o del alcance.
- Cabecera `X-Request-Id` en toda respuesta.

## 5. Paginación

- **Por página** (por defecto): `?page=2&per_page=25`. `per_page` máximo 100. `meta.total` puede ser `null` en colecciones grandes (se indica en el recurso).
- **Por cursor**: `?cursor=…&per_page=…` en colecciones de solo inserción o muy grandes (`/time-punches`, `/audit-logs`, `/sensitive-access-logs`, `/webhook-deliveries`, `/ai/usage-logs`). `meta.next_cursor` nulo = fin.
- Orden por defecto estable y documentado por recurso; siempre con desempate por `id`.
- Las exportaciones no paginan: `POST /exports` crea un trabajo y devuelve un archivo.

## 6. Filtros, orden, búsqueda, campos y vigencia

| Parámetro | Forma | Ejemplo |
|---|---|---|
| Filtro simple | `filter[campo]=valor` | `filter[status]=active` |
| Operadores | `filter[campo][op]=valor` · `eq ne in gte lte like null` | `filter[hire_date][gte]=2026-01-01` · `filter[site_id][in]=a,b` |
| Orden | `sort=campo,-otro` | `sort=-hire_date,last_name` |
| Texto | `q=…` sobre campos indexados del recurso | `q=quispe` |
| Campos | `fields=a,b,c` | `fields=id,code,status` |
| Expansión | `include=rel1,rel2` (lista cerrada por recurso) | `include=person,position` |
| Vigencia | `as_of=YYYY-MM-DD` | `as_of=2026-07-01` |

- Solo se puede filtrar y ordenar por campos declarados; lo demás → 400 `invalid_filter`.
- Filtrar u ordenar por un campo sensible exige el permiso por campo (si no, se podría inferir el valor).
- Filtros comunes en recursos laborales: `legal_entity_id`, `site_id`, `org_unit_id` (incluye descendientes con `filter[org_unit_id][tree]=1`), `manager_employment_id`, `status`.
- El alcance del usuario se aplica siempre, además de los filtros.
- Las vistas guardadas (`/saved-views`) almacenan filtros, columnas y orden por usuario o compartidas.

## 7. Escrituras, concurrencia e idempotencia

- `POST` crea; `PATCH` actualiza parcialmente campos **no vigenciados y no de estado**; `PUT` solo en recursos singulares (preferencias, ajustes); `DELETE` manda a la papelera (soft delete) y devuelve 204.
- Los datos con vigencia no se actualizan: se crea una fila nueva con `valid_from` (`POST /employments/{id}/compensations`). Corregir un error es una acción explícita con motivo.
- **Concurrencia optimista**: las respuestas llevan `ETag`; `PATCH` y acciones aceptan `If-Match`. Sin coincidencia → 409 `version_conflict`.
- **Idempotencia**: cabecera `Idempotency-Key` (hasta 64 caracteres, única por intento lógico) en todo `POST`.
  - Se guarda `(tenant, usuario o token, clave, hash del cuerpo, respuesta)` durante el periodo de retención definido en configuración.
  - Misma clave y mismo cuerpo → se devuelve la respuesta original con cabecera `Idempotent-Replay: true`.
  - Misma clave y cuerpo distinto → 409 `idempotency_conflict`.
  - Solicitud en curso con la misma clave → 409 con `Retry-After`.
  - Obligatoria en tokens de integración para rutas que generan pagos, archivos o efectos externos.
- Marcaciones sin conexión: `POST /time-punches/batch` con `client_id` por marcación; repetir el lote no duplica.
- Operaciones masivas: `POST /recurso/bulk` o acción sobre selección; devuelven 202 con un trabajo y un resultado por elemento (éxitos y errores), nunca «todo o nada» silencioso.

## 8. Acciones de estado

```
POST /api/v1/vacation-requests/{id}/approve
{ "comment": "Aprobado. Coordina la entrega de pendientes." }
```

- Una ruta por acción; el nombre es un verbo en `kebab-case` (`approve`, `reject`, `mark-paid`, `move-stage`).
- Cuerpo `ActionRequest`: `comment`, `reason`, `effective_on` y los campos propios de la acción (documentados en la especificación del módulo).
- Cada acción tiene su permiso (`modulo.recurso.accion`).
- La respuesta es el recurso actualizado con `meta.available_actions` (acciones que **este usuario** puede ejecutar en el nuevo estado).
- Transición inválida → 409 `invalid_transition`. Guarda de negocio no cumplida → 422 `business_rule_violated`.
- Toda acción deja auditoría y, cuando corresponde, evento de dominio.
- Si el recurso está sujeto a aprobación, `submit` crea la solicitud de aprobación; `approve` y `reject` del recurso son atajos de `POST /approval-requests/{id}/approve|reject` y validan que el usuario sea aprobador del paso actual.

## 9. Webhooks

Gestión: `/webhook-endpoints` (crear, pausar, rotar secreto, probar) y `/webhook-deliveries` (historial, reintento manual). Catálogo de eventos: `GET /events`.

Entrega:

```
POST https://cliente.example/hooks/gestiahr
Content-Type: application/json
Gestia-Event: leave.approved
Gestia-Event-Id: 01J9…
Gestia-Delivery-Id: 01J9…
Gestia-Timestamp: 1791000000
Gestia-Signature: v1=5d41402abc4b2a76b9719d911017c592…

{ "id": "01J9…", "name": "leave.approved", "version": 1,
  "occurred_at": "2026-10-02T15:04:05Z", "tenant_id": "…",
  "data": { "vacation_request_id": "…", "employment_id": "…", "starts_on": "2026-10-12", "ends_on": "2026-10-16" } }
```

- **Firma**: `v1 = HMAC-SHA256(secreto, "{Gestia-Timestamp}.{cuerpo crudo}")` en hexadecimal. El receptor debe recalcularla con comparación en tiempo constante y rechazar marcas de tiempo fuera de la ventana de tolerancia (parámetro; recomendación del lado receptor: pocos minutos).
- **Rotación de secreto**: durante la rotación se envían dos firmas (`v1=…,v1=…`), una por secreto vigente.
- **Reintentos**: respuesta distinta de 2xx o tiempo de espera agotado → reintento con espera creciente y variación aleatoria hasta el número máximo de intentos configurado; agotados, la entrega queda `exhausted` y visible. Un endpoint con fallos continuos pasa a `failing` y luego a `paused`, con aviso al administrador.
- **Entrega al menos una vez** y sin orden global garantizado: el receptor deduplica por `Gestia-Event-Id` y usa `occurred_at`.
- **Payload mínimo**: identificadores y datos no sensibles. Sueldos, diagnósticos y contenido de denuncias nunca viajan; se consultan por API con un token con permiso.
- **Seguridad de destino**: solo HTTPS; se bloquean direcciones internas y de metadatos (protección contra SSRF); sin seguir redirecciones.
- Suscripción por evento o comodín por agregado (`employee.*`).
- Eventos públicos estables desde su fase: `employee.hired`, `employee.updated`, `employee.terminated`, `leave.approved`, `payroll.closed`, `candidate.hired`. El catálogo completo está en `02-arquitectura.md` §9.2.

## 10. Límites

- Límite de solicitudes por token, por usuario y por organización; valores por plan (parámetros de plataforma, D-19). Cabeceras `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`; 429 con `Retry-After`.
- Rutas con límite propio y más estricto: `/auth/*`, rutas `/public/*`, `/ai/*`, `/mcp`, `/exports`, `/search`.
- Tamaño máximo de cuerpo JSON y de archivo por tipo (parámetros de plataforma). Los archivos no viajan por la API: se suben con URL firmada (`POST /files`).
- `per_page` ≤ 100; `include` limitado por recurso; `filter[..][in]` con un máximo de valores.
- Tiempo máximo de respuesta síncrona: lo que lo supere se diseña como trabajo (202).

## 11. Convenciones de datos

| Tipo | Forma |
|---|---|
| Identificador | Texto opaco (ver `04-modelo-de-datos.md`); no asumir orden ni formato |
| Fecha laboral | `YYYY-MM-DD` |
| Instante | ISO 8601 en UTC (`2026-10-02T15:04:05Z`) |
| Periodo | `YYYY-MM` |
| Dinero | `{ "amount": "3500.00", "currency": "PEN" }` (decimal como texto) |
| Tasa | Decimal como texto, fracción de 1 (`"0.09"`) |
| Enumeraciones | `snake_case` en inglés; la etiqueta traducida la pone el cliente con i18n |
| Texto traducible de catálogo | El valor en el idioma del usuario; `?locale=` para pedir otro |
| Campos personalizados | Objeto `custom` con las claves definidas en `/custom-fields` |

## 12. Tabla de recursos y endpoints

Generada desde el mismo origen que `openapi.yaml` (no editar a mano; ejecutar `python3 kit/tools/gen_openapi.py`). Lectura de la tabla:

- `GET, POST /x` y `GET, PATCH, DELETE /x/{id}` agrupan el CRUD; el permiso se forma con `view`, `create`, `update`, `delete`.
- `POST /x/{id}/{a,b}` son acciones de estado; una ruta y un permiso por acción.
- «sesión» = basta estar autenticado (datos propios); «público» = sin autenticación.
- Todas las rutas van bajo `/api/v1`. Las rutas `/platform/*` solo existen en el dominio de la consola SaaS.

<!-- ENDPOINTS:INICIO -->

### Fase 0 · Cimientos


**SEG · Identidad, roles y permisos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/users` | `identity.users.{view,create}` | 0 |
| GET, PATCH | `/users/{id}` | `identity.users.{view,update}` | 0 |
| POST | `/users/{id}/{invite,suspend,reactivate,reset-mfa,reset-password}` | `identity.users.{invite,suspend,reactivate,reset_mfa,reset_password}` | 0 |
| GET, POST | `/roles` | `identity.roles.{view,create}` | 0 |
| GET, PATCH, DELETE | `/roles/{id}` | `identity.roles.{view,update,delete}` | 0 |
| POST | `/roles/{id}/duplicate` | `identity.roles.duplicate` | 0 |
| GET, POST | `/api-tokens` | `identity.api_tokens.{view,create}` | 0 |
| GET | `/api-tokens/{id}` | `identity.api_tokens.view` | 0 |
| POST | `/api-tokens/{id}/revoke` | `identity.api_tokens.revoke` | 0 |
| POST | `/auth/login` | público | 0 |
| POST | `/auth/password/forgot` | público | 0 |
| POST | `/auth/password/reset` | público | 0 |
| POST | `/auth/invitation/accept` | público | 0 |
| POST | `/auth/otp/request` | público | 0 |
| POST | `/auth/otp/verify` | público | 0 |
| POST | `/auth/activation/code` | público | 0 |
| GET | `/me/sessions` | sesión | 0 |
| POST | `/me/sessions/{id}/revoke` | sesión | 0 |
| POST | `/me/password` | sesión | 0 |
| POST | `/users/{id}/revoke-sessions` | `identity.users.suspend` | 0 |
| POST | `/auth/mfa/verify` | sesión | 0 |
| GET | `/me/mfa` | sesión | 0 |
| POST | `/me/mfa/totp` | sesión | 0 |
| POST | `/me/mfa/totp/confirm` | sesión | 0 |
| POST | `/me/mfa/recovery-codes` | sesión | 0 |
| POST | `/me/mfa/disable` | sesión | 0 |
| POST | `/users/{id}/activation-code` | `identity.users.invite` | 0 |
| POST | `/roles/{id}/mfa-requirement` | `identity.security_policies.update` | 0 |
| POST | `/auth/logout` | sesión | 0 |
| GET | `/me` | sesión | 0 |
| GET | `/permissions` | `identity.roles.view` | 0 |

**SAS · Consola SaaS**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/platform/tenants` | `platform.tenants.{view,create}` | 0 |
| GET, PATCH | `/platform/tenants/{id}` | `platform.tenants.{view,update}` | 0 |
| POST | `/platform/tenants/{id}/{suspend,reactivate,cancel}` | `platform.tenants.{suspend,reactivate,cancel}` | 0 |
| GET, POST | `/platform/plans` | `platform.plans.{view,create}` | 0 |
| GET, PATCH | `/platform/plans/{id}` | `platform.plans.{view,update}` | 0 |
| POST | `/platform/plans/{id}/{duplicate,activate,retire}` | `platform.plans.{duplicate,activate,retire}` | 0 |
| GET | `/platform/addons` | `platform.plans.view` | 0 |
| GET, PATCH | `/platform/addons/{id}` | `platform.plans.{view,update}` | 0 |
| POST | `/platform/addons/{id}/{activate,retire}` | `platform.plans.{activate,retire}` | 0 |
| GET, POST | `/platform/subscriptions` | `platform.subscriptions.{view,create}` | 0 |
| GET, PATCH | `/platform/subscriptions/{id}` | `platform.subscriptions.{view,update}` | 0 |
| POST | `/platform/subscriptions/{id}/{change-plan,suspend,reactivate}` | `platform.subscriptions.{change_plan,suspend,reactivate}` | 0 |
| GET, POST | `/platform/feature-flags` | `platform.feature_flags.{view,create}` | 0 |
| GET, PATCH | `/platform/feature-flags/{id}` | `platform.feature_flags.{view,update}` | 0 |
| GET, POST | `/platform/support-sessions` | `platform.support_sessions.{view,create}` | 0 |
| GET | `/platform/support-sessions/{id}` | `platform.support_sessions.view` | 0 |
| POST | `/platform/support-sessions/{id}/end` | `platform.support_sessions.end` | 0 |
| GET | `/platform/health` | `platform.health.view` | 0 |
| GET | `/platform/metrics` | `platform.metrics.view` | 0 |
| POST | `/platform/auth/login` | público | 0 |
| POST | `/platform/auth/logout` | `platform` | 0 |
| GET | `/platform/me` | `platform` | 0 |
| POST | `/platform/auth/mfa/totp` | `platform` | 0 |
| POST | `/platform/auth/mfa/totp/confirm` | `platform` | 0 |
| POST | `/platform/auth/mfa/verify` | `platform` | 0 |
| GET | `/platform/audit-events` | `platform.audit.view` | 0 |
| POST | `/platform/tenants/{id}/activate` | `platform.tenants.reactivate` | 0 |
| POST | `/platform/tenants/{id}/block` | `platform.tenants.suspend` | 0 |
| GET | `/platform/tenants/{id}/status-events` | `platform.tenants.view` | 0 |
| GET | `/platform/tenants/{id}/modules` | `platform.subscriptions.view` | 0 |
| POST | `/platform/tenants/{id}/modules/{code}/activate` | `platform.subscriptions.update` | 0 |
| POST | `/platform/tenants/{id}/modules/{code}/deactivate` | `platform.subscriptions.update` | 0 |
| GET | `/platform/plans/{id}/versions` | `platform.plans.view` | 0 |
| GET | `/platform/subscriptions/{id}/events` | `platform.subscriptions.view` | 0 |
| POST | `/platform/subscriptions/{id}/convert` | `platform.subscriptions.change_plan` | 0 |
| POST | `/platform/subscriptions/{id}/cancel` | `platform.tenants.cancel` | 0 |
| POST | `/platform/subscriptions/{id}/addons` | `platform.subscriptions.update` | 0 |
| POST | `/platform/subscriptions/{id}/addons/{addonId}/remove` | `platform.subscriptions.update` | 0 |
| GET | `/platform/tenants/{id}/usage` | `platform.subscriptions.view` | 0 |
| GET | `/platform/subscriptions/{id}/plan-fit` | `platform.subscriptions.view` | 0 |
| POST | `/platform/feature-flags/{id}/archive` | `platform.feature_flags.update` | 0 |
| POST | `/platform/feature-flags/{id}/restore` | `platform.feature_flags.update` | 0 |
| GET | `/platform/feature-flags/{id}/tenants` | `platform.feature_flags.view` | 0 |
| POST | `/platform/feature-flags/{id}/tenants` | `platform.feature_flags.update` | 0 |
| POST | `/platform/feature-flags/{id}/tenants/{tenantId}/remove` | `platform.feature_flags.update` | 0 |
| GET | `/platform/tenants/{id}/feature-flags` | `platform.feature_flags.view` | 0 |
| GET | `/usage` | `settings.organization.view` | 0 |

**TEN · Organización (tenant)**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/catalogs/organization` | `org.legal_entities.view` | 0 |
| GET | `/tenant` | `settings.organization.view` | 0 |
| PATCH | `/tenant` | `settings.organization.update` | 0 |

**AUD · Auditoría**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/audit-logs` | `audit.logs.view` | 0 |
| GET | `/audit-logs/{id}` | `audit.logs.view` | 0 |
| GET | `/sensitive-access-logs` | `audit.sensitive_access.view` | 0 |
| GET | `/activity-events` | `audit.activity.view` | 0 |
| GET | `/audit-integrity-checks` | `audit.logs.view` | 0 |
| GET | `/audit-archives` | `audit.logs.view` | 0 |
| GET | `/audit-archives/{id}` | `audit.logs.view` | 0 |
| GET | `/trash` | `audit.trash.view` | 0 |
| POST | `/trash/{id}/restore` | `audit.trash.restore` | 0 |
| GET | `/audit-archives/{id}/entries` | `audit.logs.view` | 0 |
| GET | `/audit-retention` | `audit.logs.view` | 0 |

**WFL · Aprobaciones y workflows**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/approval-flows` | `workflow.approval_flows.{view,create}` | 0 |
| GET, PATCH | `/approval-flows/{id}` | `workflow.approval_flows.{view,update}` | 0 |
| POST | `/approval-flows/{id}/{publish,retire}` | `workflow.approval_flows.{publish,retire}` | 0 |
| GET | `/approval-requests` | `workflow.approval_requests.view` | 0 |
| GET | `/approval-requests/{id}` | `workflow.approval_requests.view` | 0 |
| POST | `/approval-requests/{id}/{approve,reject,delegate}` | `workflow.approval_requests.{approve,reject,delegate}` | 0 |
| GET, POST | `/workflows` | `workflow.workflows.{view,create}` | 0 |
| GET, PATCH | `/workflows/{id}` | `workflow.workflows.{view,update}` | 0 |
| POST | `/workflows/{id}/{activate,pause,retire}` | `workflow.workflows.{activate,pause,retire}` | 0 |
| GET | `/workflow-runs` | `workflow.runs.view` | 0 |
| GET | `/workflow-runs/{id}` | `workflow.runs.view` | 0 |
| POST | `/workflow-runs/{id}/{retry,cancel}` | `workflow.runs.{retry,cancel}` | 0 |
| GET, POST | `/tasks` | `workflow.tasks.{view,create}` | 0 |
| GET, PATCH | `/tasks/{id}` | `workflow.tasks.{view,update}` | 0 |
| POST | `/tasks/{id}/{complete,cancel}` | `workflow.tasks.{complete,cancel}` | 0 |
| GET | `/inbox` | sesión | 0 |
| POST | `/approval-requests/bulk-approve` | `workflow.approval_requests.approve` | 0 |
| GET | `/workflows/catalog` | `workflow.workflows.view` | 0 |
| POST | `/workflows/{id}/test` | `workflow.workflows.update` | 0 |

**NOT · Notificaciones**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/notification-templates` | `notifications.templates.{view,create}` | 0 |
| GET, PATCH | `/notification-templates/{id}` | `notifications.templates.{view,update}` | 0 |
| GET | `/notifications` | sesión | 0 |
| POST | `/notifications/read` | sesión | 0 |
| GET | `/notification-preferences` | sesión | 0 |
| PUT | `/notification-preferences` | sesión | 0 |
| GET | `/notification-deliveries` | `notifications.deliveries.view` | 0 |
| POST | `/notification-templates/{id}/request-approval` | `notifications.templates.update` | 0 |
| POST | `/webhooks/email` | público | 0 |
| GET | `/message-channels/email` | `notifications.channels.update` | 0 |
| PUT | `/message-channels/email` | `notifications.channels.update` | 0 |
| POST | `/message-channels/email/verify` | `notifications.channels.update` | 0 |
| POST | `/notification-deliveries/{id}/clear-bounce` | `notifications.channels.update` | 0 |

**CFG · Configuración y campos personalizados**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/custom-fields` | `settings.custom_fields.{view,create}` | 0 |
| GET, PATCH, DELETE | `/custom-fields/{id}` | `settings.custom_fields.{view,update,delete}` | 0 |
| GET, POST | `/exchange-rates` | `settings.exchange_rates.{view,create}` | 0 |
| GET, POST | `/saved-views` | `settings.saved_views.{view,create}` | 0 |
| GET, PATCH, DELETE | `/saved-views/{id}` | `settings.saved_views.{view,update,delete}` | 0 |
| GET | `/settings` | `settings.parameters.view` | 0 |
| GET | `/settings/{group}` | `settings.parameters.view` | 0 |
| PUT | `/settings/{group}` | `settings.parameters.update` | 0 |
| GET | `/custom-fields/form` | sesión | 0 |
| GET | `/legal-entities/{id}/logo` | `org.legal_entities.view` | 0 |
| POST | `/legal-entities/{id}/logo` | `org.legal_entities.update` | 0 |
| DELETE | `/legal-entities/{id}/logo` | `org.legal_entities.update` | 0 |
| GET | `/exchange-rates/convert` | `settings.exchange_rates.view` | 0 |
| GET | `/catalogs` | sesión | 0 |
| POST | `/catalogs` | `settings.catalogs.update` | 0 |
| GET | `/catalogs/{code}/items` | sesión | 0 |
| POST | `/catalogs/{code}/items` | `settings.catalogs.update` | 0 |
| PATCH | `/catalog-items/{id}` | `settings.catalogs.update` | 0 |
| POST | `/catalog-items/{id}/archive` | `settings.catalogs.update` | 0 |
| POST | `/catalog-items/{id}/reactivate` | `settings.catalogs.update` | 0 |
| PATCH | `/me/preferences` | sesión | 0 |
| GET | `/calendar` | `time.calendar.view` | 0 |

**PLT · Plataforma técnica**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/health` | público | 0 |
| GET | `/search` | sesión | 0 |
| POST | `/files` | sesión | 0 |
| GET | `/files/{id}/download-url` | sesión | 0 |

**WEB · Sitio comercial**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/platform/web-products` | `platform.web_products.view` | 0 |
| GET, PATCH | `/platform/web-products/{id}` | `platform.web_products.{view,update}` | 0 |
| POST | `/platform/web-products/{id}/{publish,hide}` | `platform.web_products.{publish,hide}` | 0 |
| GET | `/platform/web-leads` | `platform.web_leads.view` | 0 |
| GET, PATCH | `/platform/web-leads/{id}` | `platform.web_leads.{view,update}` | 0 |
| POST | `/platform/web-leads/{id}/{assign,convert,discard}` | `platform.web_leads.{assign,convert,discard}` | 0 |
| GET | `/platform/web-demo-bookings` | `platform.web_demo_bookings.view` | 0 |
| GET, PATCH | `/platform/web-demo-bookings/{id}` | `platform.web_demo_bookings.{view,update}` | 0 |
| POST | `/platform/web-demo-bookings/{id}/{cancel,complete}` | `platform.web_demo_bookings.{cancel,complete}` | 0 |
| GET | `/platform/web-complaints` | `platform.web_complaints.view` | 0 |
| GET | `/platform/web-complaints/{id}` | `platform.web_complaints.view` | 0 |
| POST | `/platform/web-complaints/{id}/{answer,close}` | `platform.web_complaints.{answer,close}` | 0 |
| GET | `/public/catalog` | público | 0 |
| GET | `/public/ruc/{ruc}` | público | 0 |
| GET | `/public/subdomains/{slug}` | público | 0 |
| POST | `/public/signups` | público | 0 |
| PATCH | `/public/signups/{token}` | público | 0 |
| POST | `/public/signups/{token}/complete` | público | 0 |
| POST | `/public/leads` | público | 0 |
| GET | `/public/demo-slots` | público | 0 |
| POST | `/public/demo-bookings` | público | 0 |
| POST | `/public/demo-bookings/{token}/cancel` | público | 0 |
| POST | `/public/chat/messages` | público | 0 |
| POST | `/public/complaints` | público | 0 |
| GET | `/platform/web-metrics` | `platform.web_leads.view` | 0 |
| GET | `/platform/web-settings` | `platform.web_settings.view` | 0 |
| PATCH | `/platform/web-settings` | `platform.web_settings.update` | 0 |

**RPT · Reportes**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/exports` | `reports.exports.{view,create}` | 0 |
| GET | `/exports/{id}` | `reports.exports.view` | 0 |
| GET | `/exports/{id}/download` | `reports.exports.view` | 0 |

### Fase 1 · Core HR


**ORG · Estructura organizacional**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/legal-entities` | `org.legal_entities.{view,create}` | 1 |
| GET, PATCH | `/legal-entities/{id}` | `org.legal_entities.{view,update}` | 1 |
| POST | `/legal-entities/{id}/{archive,restore}` | `org.legal_entities.{archive,restore}` | 1 |
| GET, POST | `/sites` | `org.sites.{view,create}` | 1 |
| GET, PATCH | `/sites/{id}` | `org.sites.{view,update}` | 1 |
| POST | `/sites/{id}/{archive,restore}` | `org.sites.{archive,restore}` | 1 |
| GET, POST | `/org-units` | `org.units.{view,create}` | 1 |
| GET, PATCH | `/org-units/{id}` | `org.units.{view,update}` | 1 |
| POST | `/org-units/{id}/{archive,restore,move}` | `org.units.{archive,restore,move}` | 1 |
| GET, POST | `/cost-centers` | `org.cost_centers.{view,create}` | 1 |
| GET, PATCH | `/cost-centers/{id}` | `org.cost_centers.{view,update}` | 1 |
| POST | `/cost-centers/{id}/{archive,restore}` | `org.cost_centers.{archive,restore}` | 1 |
| GET, POST | `/job-profiles` | `org.job_profiles.{view,create}` | 1 |
| GET, PATCH | `/job-profiles/{id}` | `org.job_profiles.{view,update}` | 1 |
| POST | `/job-profiles/{id}/{archive,restore}` | `org.job_profiles.{archive,restore}` | 1 |
| GET, POST | `/job-families` | `org.job_profiles.{view,create}` | 1 |
| GET, PATCH | `/job-families/{id}` | `org.job_profiles.{view,update}` | 1 |
| POST | `/job-families/{id}/{archive,restore}` | `org.job_profiles.{archive,restore}` | 1 |
| GET, POST | `/job-levels` | `org.job_profiles.{view,create}` | 1 |
| GET, PATCH | `/job-levels/{id}` | `org.job_profiles.{view,update}` | 1 |
| POST | `/job-levels/{id}/{archive,restore}` | `org.job_profiles.{archive,restore}` | 1 |
| GET, POST | `/job-categories` | `org.job_profiles.{view,create}` | 1 |
| GET, PATCH | `/job-categories/{id}` | `org.job_profiles.{view,update}` | 1 |
| POST | `/job-categories/{id}/{archive,restore}` | `org.job_profiles.{archive,restore}` | 1 |
| GET | `/org-chart` | `people.directory.view` | 1 |
| GET | `/job-profiles/{id}/versions` | `org.job_profiles.view` | 1 |
| GET | `/legal-entities/{id}/regimes` | `org.legal_entities.view` | 1 |
| GET | `/directory` | `people.directory.view` | 1 |
| GET | `/directory/{employment_id}` | `people.directory.view` | 1 |
| POST | `/cost-centers/{id}/close` | `org.cost_centers.update` | 1 |
| POST | `/cost-centers/{id}/reopen` | `org.cost_centers.update` | 1 |
| GET | `/employments/{employment_id}/cost-distributions` | `people.assignments.view` | 1 |
| POST | `/employments/{employment_id}/cost-distributions` | `people.assignments.create` | 1 |
| GET | `/org-units/{id}/history` | `org.units.view` | 1 |
| GET | `/org-units/{id}/impact` | `org.units.view` | 1 |
| GET | `/org-unit-options` | `org.units.view` | 1 |
| GET | `/org-unit-types` | `org.units.view` | 1 |
| POST | `/org-unit-types` | `org.units.update` | 1 |
| PATCH | `/org-unit-types/{id}` | `org.units.update` | 1 |

**POS · Posiciones y headcount**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/positions` | `org.positions.{view,create}` | 1 |
| GET, PATCH | `/positions/{id}` | `org.positions.{view,update}` | 1 |
| POST | `/positions/{id}/{approve,freeze,unfreeze,close}` | `org.positions.{approve,freeze,unfreeze,close}` | 1 |
| GET, POST | `/headcount-plans` | `org.headcount_plans.{view,create}` | 1 |
| GET, PATCH | `/headcount-plans/{id}` | `org.headcount_plans.{view,update}` | 1 |
| POST | `/headcount-plans/{id}/{submit,approve,close}` | `org.headcount_plans.{submit,approve,close}` | 1 |
| GET | `/headcount` | `org.positions.view` | 1 |
| POST | `/positions/{id}/reject` | `org.positions.approve` | 1 |
| POST | `/positions/{id}/open-recruitment` | `org.positions.update` | 1 |
| POST | `/positions/{id}/cancel-recruitment` | `org.positions.update` | 1 |
| GET | `/positions/{id}/occupants` | `org.positions.view` | 1 |
| GET | `/positions/{id}/status-events` | `org.positions.view` | 1 |
| GET | `/positions/{id}/reporting-line` | `org.positions.view` | 1 |
| GET | `/position-options` | `org.positions.view` | 1 |
| GET | `/position-requests` | `org.position_requests.view` | 1 |
| POST | `/position-requests` | `org.position_requests.create` | 1 |
| GET | `/position-requests/{id}` | `org.position_requests.view` | 1 |
| PATCH | `/position-requests/{id}` | `org.position_requests.create` | 1 |
| POST | `/position-requests/{id}/submit` | `org.position_requests.create` | 1 |
| POST | `/position-requests/{id}/cancel` | `org.position_requests.create` | 1 |
| GET | `/headcount-budgets` | `org.headcount_plans.view` | 1 |
| POST | `/headcount-budgets` | `org.headcount_plans.create` | 1 |
| GET | `/headcount-budgets/{id}` | `org.headcount_plans.view` | 1 |
| PATCH | `/headcount-budgets/{id}` | `org.headcount_plans.update` | 1 |
| GET | `/headcount/positions` | `org.positions.view` | 1 |

**COL · Colaboradores**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/people` | `people.people.{view,create}` | 1 |
| GET, PATCH | `/people/{id}` | `people.people.{view,update}` | 1 |
| POST | `/people/{id}/merge` | `people.people.merge` | 1 |
| GET, POST | `/employments` | `people.employments.{view,create}` | 1 |
| GET, PATCH | `/employments/{id}` | `people.employments.{view,update}` | 1 |
| POST | `/employments/{id}/{activate,suspend,terminate,cancel-termination}` | `people.employments.{activate,suspend,terminate,cancel_termination}` | 1 |
| GET, POST | `/employments/{employment_id}/dependents` | `people.dependents.{view,create}` | 1 |
| GET, PATCH, DELETE | `/employments/{employment_id}/dependents/{id}` | `people.dependents.{view,update,delete}` | 1 |
| GET | `/people/document-match` | `people.people.view` | 1 |
| GET | `/employment-options` | `people.employments.view` | 1 |
| POST | `/employments/{id}/cancel` | `people.employments.update` | 1 |
| GET | `/termination-reasons` | `people.employments.terminate` | 1 |
| GET | `/employments/{id}/profile` | `people.employments.view` | 1 |
| GET | `/employments/{id}/work-data` | `people.employments.view` | 1 |
| GET | `/people/{id}/photo` | `people.people.view` | 1 |
| POST | `/people/{id}/photo` | `people.people.update` | 1 |
| DELETE | `/people/{id}/photo` | `people.people.update` | 1 |
| GET | `/people/{id}/addresses` | `people.people.view` | 1 |
| POST | `/people/{id}/addresses` | `people.people.update` | 1 |
| PATCH | `/people/{id}/addresses/{address_id}` | `people.people.update` | 1 |
| DELETE | `/people/{id}/addresses/{address_id}` | `people.people.update` | 1 |
| GET | `/people/{id}/emergency-contacts` | `people.people.view` | 1 |
| POST | `/people/{id}/emergency-contacts` | `people.people.update` | 1 |
| PATCH | `/people/{id}/emergency-contacts/{contact_id}` | `people.people.update` | 1 |
| DELETE | `/people/{id}/emergency-contacts/{contact_id}` | `people.people.update` | 1 |
| GET | `/people/{id}/sensitive-data` | `people.people.view` | 1 |
| PUT | `/people/{id}/sensitive-data` | `people.people.update` | 1 |
| POST | `/employments/{employment_id}/dependents/{id}/deregister` | `people.dependents.update` | 1 |
| GET | `/employments/{employment_id}/dependents/{id}/proof` | `people.dependents.view` | 1 |
| POST | `/employments/{employment_id}/dependents/{id}/proof` | `people.dependents.update` | 1 |
| GET | `/employments/{employment_id}/pension-affiliations` | `people.employments.view` | 1 |
| POST | `/employments/{employment_id}/pension-affiliations` | `people.employments.update` | 1 |
| GET | `/employments/{employment_id}/health-regimes` | `people.employments.view` | 1 |
| POST | `/employments/{employment_id}/health-regimes` | `people.employments.update` | 1 |
| GET | `/employments/{employment_id}/insurance-coverages` | `people.employments.view` | 1 |
| POST | `/employments/{employment_id}/insurance-coverages` | `people.employments.update` | 1 |
| POST | `/employments/{employment_id}/insurance-coverages/{id}/end` | `people.employments.update` | 1 |
| GET | `/employments/{employment_id}/life-insurance-declarations` | `people.employments.view` | 1 |
| POST | `/employments/{employment_id}/life-insurance-declarations` | `people.employments.update` | 1 |
| GET | `/employments/{employment_id}/life-insurance-declarations/{id}/file` | `people.employments.view` | 1 |
| GET | `/pension-funds` | `people.employments.view` | 1 |
| GET | `/health-providers` | `people.employments.view` | 1 |
| POST | `/health-providers` | `org.legal_entities.update` | 1 |
| GET | `/insurance-policies` | `org.legal_entities.view` | 1 |
| POST | `/insurance-policies` | `org.legal_entities.update` | 1 |
| PATCH | `/insurance-policies/{id}` | `org.legal_entities.update` | 1 |
| GET | `/employments/{employment_id}/bank-accounts` | `people.employments.view` | 1 |
| POST | `/employments/{employment_id}/bank-accounts` | `people.people.bank_account.edit` | 1 |
| POST | `/employments/{employment_id}/bank-accounts/{id}/approve` | `people.change_requests.approve` | 1 |
| POST | `/employments/{employment_id}/bank-accounts/{id}/reject` | `people.change_requests.approve` | 1 |
| POST | `/employments/{employment_id}/bank-accounts/{id}/cancel` | `people.people.bank_account.edit` | 1 |
| GET | `/home` | sesión | 1 |
| GET | `/home/hr` | `people.employments.view` | 1 |

**HIS · Historia con vigencia**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/employments/{employment_id}/assignments` | `people.assignments.view` | 1 |
| GET | `/employments/{employment_id}/assignments/{id}` | `people.assignments.view` | 1 |
| GET | `/employments/{employment_id}/compensations` | `people.compensations.view` | 1 |
| GET | `/employments/{employment_id}/compensations/{id}` | `people.compensations.view` | 1 |
| GET, POST | `/employments/{employment_id}/compensation-changes` | `people.compensations.{view,create}` | 1 |
| GET | `/employments/{employment_id}/compensation-changes/{id}` | `people.compensations.view` | 1 |
| POST | `/employments/{employment_id}/compensation-changes/{id}/cancel` | `people.compensations.cancel` | 1 |
| GET | `/compensation-concepts` | `people.compensations.view` | 1 |
| GET | `/employments/{id}/timeline` | `people.employments.view` | 1 |
| GET | `/employments/{employment_id}/timeline` | `people.employments.view` | 1 |
| GET | `/employments/{employment_id}/personnel-actions` | `people.assignments.view` | 1 |
| POST | `/employments/{employment_id}/personnel-actions` | `people.assignments.create` | 1 |
| POST | `/employments/{employment_id}/personnel-actions/propose` | `people.assignments.propose` | 1 |
| GET | `/personnel-actions` | `people.assignments.view` | 1 |
| GET | `/personnel-actions/{id}` | `people.assignments.view` | 1 |
| PATCH | `/personnel-actions/{id}` | `people.assignments.create` | 1 |
| POST | `/personnel-actions/{id}/cancel` | `people.assignments.cancel` | 1 |
| POST | `/personnel-actions/{id}/correct` | `people.assignments.correct` | 1 |

**CON · Contratos y adendas**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/contract-types` | `contracts.contract_types.{view,create}` | 1 |
| GET, PATCH | `/contract-types/{id}` | `contracts.contract_types.{view,update}` | 1 |
| GET, POST | `/contracts` | `contracts.contracts.{view,create}` | 1 |
| GET, PATCH | `/contracts/{id}` | `contracts.contracts.{view,update}` | 1 |
| GET, POST | `/contract-addenda` | `contracts.addenda.{view,create}` | 1 |
| GET | `/contract-addenda/{id}` | `contracts.addenda.view` | 1 |
| POST | `/contracts/{id}/submit` | `contracts.contracts.create` | 1 |
| POST | `/contracts/{id}/approve` | `contracts.contracts.approve` | 1 |
| POST | `/contracts/{id}/return` | `contracts.contracts.approve` | 1 |
| POST | `/contracts/{id}/register-signature` | `contracts.contracts.issue` | 1 |
| POST | `/contracts/{id}/decline-signature` | `contracts.contracts.issue` | 1 |
| POST | `/contracts/{id}/void` | `contracts.contracts.update` | 1 |
| POST | `/contracts/{id}/terminate` | `contracts.contracts.terminate` | 1 |
| POST | `/contracts/{id}/renew` | `contracts.contracts.renew` | 1 |
| GET | `/contracts/expiring` | `contracts.contracts.view` | 1 |
| GET | `/contracts/{id}/document` | `contracts.contracts.view` | 1 |
| GET | `/employments/{employment_id}/contracts` | `contracts.contracts.view` | 1 |
| POST | `/contract-addenda/{id}/issue` | `contracts.addenda.issue` | 1 |
| POST | `/contract-addenda/{id}/register-signature` | `contracts.addenda.issue` | 1 |
| POST | `/contract-addenda/{id}/cancel` | `contracts.addenda.cancel` | 1 |
| GET | `/contract-addenda/{id}/document` | `contracts.addenda.view` | 1 |

**DOC · Documentos y firma**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/documents` | `documents.documents.view` | 1 |
| GET, PATCH | `/documents/{id}` | `documents.documents.{view,update}` | 1 |
| POST | `/documents/{id}/{archive,restore}` | `documents.documents.{archive,restore}` | 1 |
| GET, POST | `/document-templates` | `documents.templates.{view,create}` | 1 |
| GET, PATCH | `/document-templates/{id}` | `documents.templates.{view,update}` | 1 |
| POST | `/document-templates/{id}/{publish,retire}` | `documents.templates.{publish,retire}` | 1 |
| GET | `/document-generation-batches` | `documents.templates.view` | 1 |
| GET | `/document-generation-batches/{id}` | `documents.templates.view` | 1 |
| GET | `/document-requests` | `documents.documents.view` | 1 |
| GET | `/document-requests/{id}` | `documents.documents.view` | 1 |
| GET, POST | `/signature-requests` | `documents.signature_requests.{view,create}` | 1 |
| GET | `/signature-requests/{id}` | `documents.signature_requests.view` | 1 |
| POST | `/signature-requests/{id}/{send,sign,decline,cancel,remind}` | `documents.signature_requests.{send,sign,decline,cancel,remind}` | 1 |
| GET | `/document-categories` | `documents.documents.view` | 1 |
| POST | `/document-categories` | `documents.document_types.update` | 1 |
| PATCH | `/document-categories/{id}` | `documents.document_types.update` | 1 |
| POST | `/document-categories/{id}/archive` | `documents.document_types.update` | 1 |
| POST | `/document-categories/{id}/restore` | `documents.document_types.update` | 1 |
| POST | `/documents` | `documents.documents.create` | 1 |
| POST | `/documents/{id}/approve` | `documents.documents.approve` | 1 |
| POST | `/documents/{id}/reject` | `documents.documents.approve` | 1 |
| DELETE | `/documents/{id}` | `documents.documents.update` | 1 |
| GET | `/documents/expiring` | `documents.documents.view` | 1 |
| GET | `/documents/{id}/versions` | `documents.documents.view` | 1 |
| POST | `/documents/{id}/versions` | `documents.documents.new_version` | 1 |
| GET | `/documents/{id}/file` | `documents.documents.view` | 1 |
| GET | `/documents/{id}/versions/{version}/file` | `documents.documents.view` | 1 |
| GET | `/employments/{employment_id}/documents` | `documents.documents.view` | 1 |
| GET | `/employments/{employment_id}/linked-documents/{ref}/file` | `documents.documents.view` | 1 |
| POST | `/document-requests` | `documents.documents.request` | 1 |
| POST | `/document-requests/{id}/submit` | `documents.documents.create` | 1 |
| POST | `/document-requests/{id}/cancel` | `documents.documents.request` | 1 |
| GET | `/document-templates/variables` | `documents.templates.view` | 1 |
| POST | `/document-templates/{id}/preview` | `documents.templates.generate` | 1 |
| POST | `/document-templates/{id}/generate` | `documents.templates.generate` | 1 |

**ESS · Portal del colaborador**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/profile-change-requests` | `people.change_requests.{view,create}` | 1 |
| GET | `/profile-change-requests/{id}` | `people.change_requests.view` | 1 |
| POST | `/profile-change-requests/{id}/{approve,reject,cancel}` | `people.change_requests.{approve,reject,cancel}` | 1 |
| GET | `/me/employment` | sesión | 1 |
| GET | `/me/home` | sesión | 1 |

**MSS · Portal del manager**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/home/manager` | `people.team.view` | 1 |
| GET | `/my-team` | `people.team.view` | 1 |
| GET | `/my-team/calendar` | `people.team.view` | 1 |

**IMP · Importador y puesta en marcha**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/imports` | `imports.imports.{view,create}` | 1 |
| GET | `/imports/{id}` | `imports.imports.view` | 1 |
| POST | `/imports/{id}/{map,validate,execute,cancel,revert}` | `imports.imports.{map,validate,execute,cancel,revert}` | 1 |
| GET | `/import-types` | `imports.imports.view` | 1 |
| GET | `/imports/{id}/rows` | `imports.imports.view` | 1 |
| GET | `/imports/{id}/errors/download` | `imports.imports.view` | 1 |
| GET | `/setup-checklist` | `imports.imports.view` | 1 |

**AYU · Centro de ayuda**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/platform/help-articles` | `platform.help_articles.{view,create}` | 1 |
| GET, PATCH | `/platform/help-articles/{id}` | `platform.help_articles.{view,update}` | 1 |
| POST | `/platform/help-articles/{id}/{publish,archive}` | `platform.help_articles.{publish,archive}` | 1 |
| GET, POST | `/help/org-articles` | `help.org_articles.{view,create}` | 1 |
| GET, PATCH | `/help/org-articles/{id}` | `help.org_articles.{view,update}` | 1 |
| POST | `/help/org-articles/{id}/{publish,archive}` | `help.org_articles.{publish,archive}` | 1 |
| GET | `/help/articles` | `help.articles.view` | 1 |
| GET | `/help/articles/{id}` | `help.articles.view` | 1 |
| POST | `/help/articles/{id}/feedback` | `help.articles.view` | 1 |
| GET | `/help/search` | `help.articles.view` | 1 |
| GET | `/help/screens/{screen_key}` | `help.articles.view` | 1 |
| GET | `/help/whats-new` | `help.articles.view` | 1 |
| POST | `/help/tours/{tour_key}/complete` | `help.articles.view` | 1 |
| GET | `/help/escalations` | `help.escalations.view` | 1 |
| POST | `/help/escalations` | `help.escalations.create` | 1 |
| GET | `/help/insights` | `help.insights.view` | 1 |
| GET | `/platform/help-escalations` | `platform.help_articles.view` | 1 |
| GET | `/platform/help-insights` | `platform.help_articles.view` | 1 |

### Fase 2 · Tiempo


**ASI · Asistencia y marcación**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/time-punches` | `time.punches.{view,create}` | 2 |
| GET | `/time-punches/{id}` | `time.punches.view` | 2 |
| GET | `/attendance-days` | `time.attendance_days.view` | 2 |
| GET | `/attendance-days/{id}` | `time.attendance_days.view` | 2 |
| POST | `/attendance-days/{id}/{justify,recalculate}` | `time.attendance_days.{justify,recalculate}` | 2 |
| GET, POST | `/punch-devices` | `time.devices.{view,create}` | 2 |
| GET, PATCH | `/punch-devices/{id}` | `time.devices.{view,update}` | 2 |
| POST | `/punch-devices/{id}/{disable,enable}` | `time.devices.{disable,enable}` | 2 |
| GET, POST | `/geofences` | `time.geofences.{view,create}` | 2 |
| GET, PATCH | `/geofences/{id}` | `time.geofences.{view,update}` | 2 |
| POST | `/geofences/{id}/archive` | `time.geofences.archive` | 2 |
| POST | `/time-punches/batch` | `time.punches.create` | 2 |
| POST | `/attendance-periods/{period}/close` | `time.attendance_days.close` | 2 |

**TUR · Horarios y turnos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/work-schedules` | `time.schedules.{view,create}` | 2 |
| GET, PATCH | `/work-schedules/{id}` | `time.schedules.{view,update}` | 2 |
| POST | `/work-schedules/{id}/archive` | `time.schedules.archive` | 2 |
| GET, POST | `/shifts` | `time.shifts.{view,create}` | 2 |
| GET, PATCH | `/shifts/{id}` | `time.shifts.{view,update}` | 2 |
| POST | `/shifts/{id}/archive` | `time.shifts.archive` | 2 |
| GET, POST | `/shift-assignments` | `time.shift_assignments.{view,create}` | 2 |
| GET, PATCH, DELETE | `/shift-assignments/{id}` | `time.shift_assignments.{view,update,delete}` | 2 |
| POST | `/shift-assignments/{id}/publish` | `time.shift_assignments.publish` | 2 |
| GET, POST | `/holiday-calendars` | `time.holiday_calendars.{view,create}` | 2 |
| GET, PATCH | `/holiday-calendars/{id}` | `time.holiday_calendars.{view,update}` | 2 |
| POST | `/shift-assignments/bulk` | `time.shift_assignments.create` | 2 |

**VAC · Vacaciones**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/vacation-balances` | `time.vacation_balances.view` | 2 |
| GET | `/vacation-balances/{id}` | `time.vacation_balances.view` | 2 |
| POST | `/vacation-balances/{id}/adjust` | `time.vacation_balances.adjust` | 2 |
| GET | `/vacation-policies` | `time.vacation_policies.view` | 2 |
| GET | `/vacation-policies/{id}` | `time.vacation_policies.view` | 2 |
| GET, POST | `/vacation-requests` | `time.vacation_requests.{view,create}` | 2 |
| GET, PATCH | `/vacation-requests/{id}` | `time.vacation_requests.{view,update}` | 2 |
| POST | `/vacation-requests/{id}/{submit,cancel}` | `time.vacation_requests.{submit,cancel}` | 2 |
| POST | `/vacation-policies` | `time.vacation_policies.update` | 2 |
| PATCH | `/vacation-policies/{id}` | `time.vacation_policies.update` | 2 |
| POST | `/vacation-policies/{id}/archive` | `time.vacation_policies.update` | 2 |
| POST | `/vacation-policies/{id}/restore` | `time.vacation_policies.update` | 2 |
| GET | `/vacation-policies/{id}/rules` | `time.vacation_policies.view` | 2 |
| GET | `/vacation-legal-limits` | `time.vacation_policies.view` | 2 |
| GET | `/vacation-balances/{id}/ledger` | `time.vacation_balances.view` | 2 |
| POST | `/vacation-requests/simulate` | `time.vacation_requests.create` | 2 |
| POST | `/vacation-requests/{id}/register` | `time.vacation_requests.validate` | 2 |
| GET | `/vacation-requests/{id}/overlaps` | `time.vacation_requests.view` | 2 |
| GET | `/team-calendar` | `time.vacation_requests.view` | 2 |

**AUS · Ausencias y permisos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/absence-types` | `time.absence_types.{view,create}` | 2 |
| GET, PATCH | `/absence-types/{id}` | `time.absence_types.{view,update}` | 2 |
| POST | `/absence-types/{id}/archive` | `time.absence_types.archive` | 2 |
| GET, POST | `/absences` | `time.absences.{view,create}` | 2 |
| GET, PATCH | `/absences/{id}` | `time.absences.{view,update}` | 2 |
| POST | `/absences/{id}/{submit,cancel}` | `time.absences.{submit,cancel}` | 2 |
| GET | `/absence-types/{id}/rules` | `time.absence_types.view` | 2 |
| POST | `/absences/simulate` | `time.absences.create` | 2 |
| POST | `/absences/{id}/register` | `time.absences.create_for_others` | 2 |
| POST | `/absences/{id}/attachments` | `time.absences.update` | 2 |
| GET | `/absences/{id}/attachments/{attachment_id}` | `time.absences.view` | 2 |
| GET | `/absences/{id}/medical-certificate` | `time.absences.medical_certificate.view` | 2 |
| PUT | `/absences/{id}/medical-certificate` | `time.absences.create_for_others` | 2 |
| GET | `/employments/{employment_id}/medical-leave-summary` | `time.absences.manage_subsidy` | 2 |
| GET | `/absence-subsidies` | `time.absences.manage_subsidy` | 2 |
| GET | `/absence-subsidies/{id}` | `time.absences.manage_subsidy` | 2 |
| POST | `/absence-subsidies/{id}/file` | `time.absences.manage_subsidy` | 2 |
| POST | `/absence-subsidies/{id}/observe` | `time.absences.manage_subsidy` | 2 |
| POST | `/absence-subsidies/{id}/approve` | `time.absences.manage_subsidy` | 2 |
| POST | `/absence-subsidies/{id}/reject` | `time.absences.manage_subsidy` | 2 |
| POST | `/absence-subsidies/{id}/reimburse` | `time.absences.manage_subsidy` | 2 |

**BHO · Horas extra y bolsa de horas**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/overtime-requests` | `time.overtime_requests.{view,create}` | 2 |
| GET | `/overtime-requests/{id}` | `time.overtime_requests.view` | 2 |
| POST | `/overtime-requests/{id}/{approve,reject,cancel}` | `time.overtime_requests.{approve,reject,cancel}` | 2 |
| GET, POST | `/time-bank-movements` | `time.time_bank.{view,create}` | 2 |

**TSH · Hojas de tiempo**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/projects` | `time.projects.{view,create}` | 2 |
| GET, PATCH | `/projects/{id}` | `time.projects.{view,update}` | 2 |
| POST | `/projects/{id}/close` | `time.projects.close` | 2 |
| GET, POST | `/timesheets` | `time.timesheets.{view,create}` | 2 |
| GET, PATCH | `/timesheets/{id}` | `time.timesheets.{view,update}` | 2 |
| POST | `/timesheets/{id}/{submit,approve,reject}` | `time.timesheets.{submit,approve,reject}` | 2 |

### Fase 3 · Nómina Perú


**NOM · Motor de nómina**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/payroll-regimes` | `payroll.regimes.view` | 3 |
| GET | `/payroll-regimes/{id}` | `payroll.regimes.view` | 3 |
| GET, POST | `/legal-entity-annual-figures` | `payroll.entity_annual_figures.{view,create}` | 3 |
| GET, PATCH | `/legal-entity-annual-figures/{id}` | `payroll.entity_annual_figures.{view,update}` | 3 |
| GET, POST | `/construction-works` | `payroll.construction_works.{view,create}` | 3 |
| GET, PATCH | `/construction-works/{id}` | `payroll.construction_works.{view,update}` | 3 |
| POST | `/construction-works/{id}/finish` | `payroll.construction_works.finish` | 3 |
| GET, POST | `/construction-assignments` | `payroll.construction_assignments.{view,create}` | 3 |
| GET | `/construction-assignments/{id}` | `payroll.construction_assignments.view` | 3 |
| POST | `/construction-assignments/{id}/cancel` | `payroll.construction_assignments.cancel` | 3 |

**CPT · Conceptos y fórmulas**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/payroll-concepts` | `payroll.concepts.{view,create}` | 3 |
| GET, PATCH | `/payroll-concepts/{id}` | `payroll.concepts.{view,update}` | 3 |
| POST | `/payroll-concepts/{id}/{publish,retire,test}` | `payroll.concepts.{publish,retire,test}` | 3 |

**PAR · Parámetros legales**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/legal-parameters` | `payroll.legal_parameters.{view,create}` | 3 |
| GET | `/legal-parameters/{id}` | `payroll.legal_parameters.view` | 3 |
| POST | `/legal-parameters/{id}/add-value` | `payroll.legal_parameters.add_value` | 3 |
| GET, POST | `/wage-tables` | `payroll.wage_tables.{view,create}` | 3 |
| GET | `/wage-tables/{id}` | `payroll.wage_tables.view` | 3 |
| POST | `/wage-tables/{id}/verify` | `payroll.wage_tables.verify` | 3 |

**PER · Periodos de planilla**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/payroll-periods` | `payroll.periods.{view,create}` | 3 |
| GET | `/payroll-periods/{id}` | `payroll.periods.view` | 3 |
| POST | `/payroll-periods/{id}/{calculate,simulate,submit,approve,reject,close,reopen,mark-paid}` | `payroll.periods.{calculate,simulate,submit,approve,reject,close,reopen,mark_paid}` | 3 |
| GET, POST | `/payroll-incidents` | `payroll.incidents.{view,create}` | 3 |
| GET, PATCH, DELETE | `/payroll-incidents/{id}` | `payroll.incidents.{view,update,delete}` | 3 |
| GET | `/payroll-results` | `payroll.results.view` | 3 |
| GET | `/payroll-results/{id}` | `payroll.results.view` | 3 |
| GET | `/payroll-periods/{id}/variance` | `payroll.periods.view` | 3 |

**BOL · Boletas**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/payslips` | `payroll.payslips.view` | 3 |
| GET | `/payslips/{id}` | `payroll.payslips.view` | 3 |
| POST | `/payslips/{id}/{publish,acknowledge,void}` | `payroll.payslips.{publish,acknowledge,void}` | 3 |
| GET | `/me/payslips` | sesión | 3 |

**BSO · Beneficios sociales**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/benefit-runs` | `payroll.benefit_runs.{view,create}` | 3 |
| GET | `/benefit-runs/{id}` | `payroll.benefit_runs.view` | 3 |
| POST | `/benefit-runs/{id}/{calculate,approve,close}` | `payroll.benefit_runs.{calculate,approve,close}` | 3 |

**PRE · Préstamos y adelantos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/loans` | `payroll.loans.{view,create}` | 3 |
| GET, PATCH | `/loans/{id}` | `payroll.loans.{view,update}` | 3 |
| POST | `/loans/{id}/{approve,reject,disburse,cancel}` | `payroll.loans.{approve,reject,disburse,cancel}` | 3 |
| GET, POST | `/salary-advances` | `payroll.salary_advances.{view,create}` | 3 |
| GET | `/salary-advances/{id}` | `payroll.salary_advances.view` | 3 |
| POST | `/salary-advances/{id}/{approve,reject,pay,cancel}` | `payroll.salary_advances.{approve,reject,pay,cancel}` | 3 |

**DEC · Declaraciones y archivos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/declaration-files` | `payroll.declaration_files.{view,create}` | 3 |
| GET | `/declaration-files/{id}` | `payroll.declaration_files.view` | 3 |
| POST | `/declaration-files/{id}/{validate,mark-submitted,rectify}` | `payroll.declaration_files.{validate,mark_submitted,rectify}` | 3 |
| GET, POST | `/employments/{employment_id}/registry-profiles` | `payroll.registry_profiles.{view,create}` | 3 |
| GET | `/employments/{employment_id}/registry-profiles/{id}` | `payroll.registry_profiles.view` | 3 |
| POST | `/employments/{employment_id}/registry-profiles/{id}/validate` | `payroll.registry_profiles.validate` | 3 |
| GET, POST | `/external-income-declarations` | `payroll.external_income_declarations.{view,create}` | 3 |
| GET | `/external-income-declarations/{id}` | `payroll.external_income_declarations.view` | 3 |
| POST | `/external-income-declarations/{id}/{validate,reject,supersede}` | `payroll.external_income_declarations.{validate,reject,supersede}` | 3 |
| GET | `/fifth-category-ledger` | `payroll.fifth_category_ledger.view` | 3 |
| GET | `/fifth-category-ledger/{id}` | `payroll.fifth_category_ledger.view` | 3 |
| GET, POST | `/fifth-category-refunds` | `payroll.fifth_category_refunds.{view,create}` | 3 |
| GET | `/fifth-category-refunds/{id}` | `payroll.fifth_category_refunds.view` | 3 |
| POST | `/fifth-category-refunds/{id}/{refund,compensate,request-authority-refund,close}` | `payroll.fifth_category_refunds.{refund,compensate,request_authority_refund,close}` | 3 |
| GET | `/statutory-file-layouts` | `payroll.file_layouts.view` | 3 |
| GET | `/statutory-file-layouts/{id}` | `payroll.file_layouts.view` | 3 |
| GET | `/parametric-tables` | `payroll.parametric_tables.view` | 3 |
| GET | `/parametric-tables/{id}` | `payroll.parametric_tables.view` | 3 |
| POST | `/declaration-files/preview` | `payroll.declaration_files.create` | 3 |
| POST | `/fifth-category/simulate` | `payroll.fifth_category_ledger.view` | 3 |
| POST | `/employments/{employment_id}/registry-validation` | `payroll.registry_profiles.view` | 3 |

**PRV · Provisiones y asientos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/provisions` | `payroll.provisions.view` | 3 |
| GET, POST | `/accounting-entries` | `payroll.accounting_entries.{view,create}` | 3 |
| GET | `/accounting-entries/{id}` | `payroll.accounting_entries.view` | 3 |
| POST | `/accounting-entries/{id}/{post,export}` | `payroll.accounting_entries.{post,export}` | 3 |

**LIQ · Liquidaciones**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/settlements` | `payroll.settlements.{view,create}` | 3 |
| GET, PATCH | `/settlements/{id}` | `payroll.settlements.{view,update}` | 3 |
| POST | `/settlements/{id}/{calculate,approve,mark-paid,void}` | `payroll.settlements.{calculate,approve,mark_paid,void}` | 3 |
| POST | `/settlements/simulate` | `payroll.settlements.simulate` | 3 |

**LOC · Locadores**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/contractors` | `payroll.contractors.{view,create}` | 3 |
| GET, PATCH | `/contractors/{id}` | `payroll.contractors.{view,update}` | 3 |
| POST | `/contractors/{id}/end` | `payroll.contractors.end` | 3 |
| GET, POST | `/contractor-invoices` | `payroll.contractor_invoices.{view,create}` | 3 |
| GET, PATCH | `/contractor-invoices/{id}` | `payroll.contractor_invoices.{view,update}` | 3 |
| POST | `/contractor-invoices/{id}/{approve,reject,mark-paid}` | `payroll.contractor_invoices.{approve,reject,mark_paid}` | 3 |

### Fase 4 · Talento


**ATS · Reclutamiento**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/job-requisitions` | `recruiting.requisitions.{view,create}` | 4 |
| GET, PATCH | `/job-requisitions/{id}` | `recruiting.requisitions.{view,update}` | 4 |
| POST | `/job-requisitions/{id}/{submit,approve,publish,hold,close}` | `recruiting.requisitions.{submit,approve,publish,hold,close}` | 4 |
| GET, POST | `/candidates` | `recruiting.candidates.{view,create}` | 4 |
| GET, PATCH | `/candidates/{id}` | `recruiting.candidates.{view,update}` | 4 |
| POST | `/candidates/{id}/anonymize` | `recruiting.candidates.anonymize` | 4 |
| GET, POST | `/applications` | `recruiting.applications.{view,create}` | 4 |
| GET, PATCH | `/applications/{id}` | `recruiting.applications.{view,update}` | 4 |
| POST | `/applications/{id}/{move-stage,reject,withdraw,hire}` | `recruiting.applications.{move_stage,reject,withdraw,hire}` | 4 |
| GET, POST | `/talent-pools` | `recruiting.talent_pools.{view,create}` | 4 |
| GET, PATCH, DELETE | `/talent-pools/{id}` | `recruiting.talent_pools.{view,update,delete}` | 4 |
| GET | `/public/jobs` | público | 4 |
| GET | `/public/jobs/{slug}` | público | 4 |
| POST | `/public/jobs/{slug}/applications` | público | 4 |

**ENT · Entrevistas y ofertas**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/interviews` | `recruiting.interviews.{view,create}` | 4 |
| GET, PATCH | `/interviews/{id}` | `recruiting.interviews.{view,update}` | 4 |
| POST | `/interviews/{id}/{complete,cancel}` | `recruiting.interviews.{complete,cancel}` | 4 |
| GET, POST | `/scorecards` | `recruiting.scorecards.{view,create}` | 4 |
| GET | `/scorecards/{id}` | `recruiting.scorecards.view` | 4 |
| GET, POST | `/offers` | `recruiting.offers.{view,create}` | 4 |
| GET, PATCH | `/offers/{id}` | `recruiting.offers.{view,update}` | 4 |
| POST | `/offers/{id}/{submit,approve,send,accept,decline,withdraw}` | `recruiting.offers.{submit,approve,send,accept,decline,withdraw}` | 4 |

**ONB · Onboarding**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/process-templates` | `onboarding.templates.{view,create}` | 4 |
| GET, PATCH | `/process-templates/{id}` | `onboarding.templates.{view,update}` | 4 |
| POST | `/process-templates/{id}/{publish,retire}` | `onboarding.templates.{publish,retire}` | 4 |
| GET, POST | `/onboarding-processes` | `onboarding.processes.{view,create}` | 4 |
| GET | `/onboarding-processes/{id}` | `onboarding.processes.view` | 4 |
| POST | `/onboarding-processes/{id}/{start,complete,cancel}` | `onboarding.processes.{start,complete,cancel}` | 4 |

**OFF · Offboarding**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/offboarding-processes` | `onboarding.offboardings.{view,create}` | 4 |
| GET, PATCH | `/offboarding-processes/{id}` | `onboarding.offboardings.{view,update}` | 4 |
| POST | `/offboarding-processes/{id}/{start,complete,cancel}` | `onboarding.offboardings.{start,complete,cancel}` | 4 |

**DES · Desempeño**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/review-cycles` | `performance.cycles.{view,create}` | 4 |
| GET, PATCH | `/review-cycles/{id}` | `performance.cycles.{view,update}` | 4 |
| POST | `/review-cycles/{id}/{launch,start-calibration,close}` | `performance.cycles.{launch,start_calibration,close}` | 4 |
| GET | `/reviews` | `performance.reviews.view` | 4 |
| GET, PATCH | `/reviews/{id}` | `performance.reviews.{view,update}` | 4 |
| POST | `/reviews/{id}/{submit,share,reopen}` | `performance.reviews.{submit,share,reopen}` | 4 |

**OKR · Objetivos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/objectives` | `performance.objectives.{view,create}` | 4 |
| GET, PATCH | `/objectives/{id}` | `performance.objectives.{view,update}` | 4 |
| POST | `/objectives/{id}/{activate,check-in,close}` | `performance.objectives.{activate,check_in,close}` | 4 |

**CMP · Competencias y habilidades**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/competencies` | `performance.competencies.{view,create}` | 4 |
| GET, PATCH | `/competencies/{id}` | `performance.competencies.{view,update}` | 4 |
| POST | `/competencies/{id}/archive` | `performance.competencies.archive` | 4 |
| GET, POST | `/skills` | `performance.skills.{view,create}` | 4 |
| GET, PATCH | `/skills/{id}` | `performance.skills.{view,update}` | 4 |
| POST | `/skills/{id}/archive` | `performance.skills.archive` | 4 |
| GET | `/employments/{employment_id}/skills` | `performance.skills.view` | 4 |
| PUT | `/employments/{employment_id}/skills` | `performance.skills.update` | 4 |

**FBK · 1:1 y feedback**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/one-on-ones` | `performance.one_on_ones.{view,create}` | 4 |
| GET, PATCH | `/one-on-ones/{id}` | `performance.one_on_ones.{view,update}` | 4 |
| POST | `/one-on-ones/{id}/{complete,cancel}` | `performance.one_on_ones.{complete,cancel}` | 4 |
| GET, POST | `/feedback` | `performance.feedback.{view,create}` | 4 |
| GET | `/feedback/{id}` | `performance.feedback.view` | 4 |

### Fase 5 · Desarrollo y compensación


**LMS · Aprendizaje**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/courses` | `learning.courses.{view,create}` | 5 |
| GET, PATCH | `/courses/{id}` | `learning.courses.{view,update}` | 5 |
| POST | `/courses/{id}/{publish,archive}` | `learning.courses.{publish,archive}` | 5 |
| GET, POST | `/enrollments` | `learning.enrollments.{view,create}` | 5 |
| GET, PATCH | `/enrollments/{id}` | `learning.enrollments.{view,update}` | 5 |
| POST | `/enrollments/{id}/{complete,reset}` | `learning.enrollments.{complete,reset}` | 5 |
| GET, POST | `/training-plans` | `learning.training_plans.{view,create}` | 5 |
| GET, PATCH | `/training-plans/{id}` | `learning.training_plans.{view,update}` | 5 |
| POST | `/training-plans/{id}/{approve,close}` | `learning.training_plans.{approve,close}` | 5 |

**CAR · Carrera y sucesión**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/development-plans` | `careers.development_plans.{view,create}` | 5 |
| GET, PATCH | `/development-plans/{id}` | `careers.development_plans.{view,update}` | 5 |
| POST | `/development-plans/{id}/{activate,complete}` | `careers.development_plans.{activate,complete}` | 5 |
| GET, POST | `/career-paths` | `careers.paths.{view,create}` | 5 |
| GET, PATCH | `/career-paths/{id}` | `careers.paths.{view,update}` | 5 |
| POST | `/career-paths/{id}/archive` | `careers.paths.archive` | 5 |
| GET, POST | `/succession-plans` | `careers.succession_plans.{view,create}` | 5 |
| GET, PATCH | `/succession-plans/{id}` | `careers.succession_plans.{view,update}` | 5 |
| POST | `/succession-plans/{id}/archive` | `careers.succession_plans.archive` | 5 |
| GET, POST | `/talent-reviews` | `careers.talent_reviews.{view,create}` | 5 |
| GET, PATCH | `/talent-reviews/{id}` | `careers.talent_reviews.{view,update}` | 5 |
| POST | `/talent-reviews/{id}/close` | `careers.talent_reviews.close` | 5 |
| GET | `/talent-reviews/{id}/nine-box` | `careers.talent_reviews.view` | 5 |

**COM · Compensaciones**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/salary-bands` | `compensation.bands.{view,create}` | 5 |
| GET, PATCH | `/salary-bands/{id}` | `compensation.bands.{view,update}` | 5 |
| GET, POST | `/compensation-cycles` | `compensation.cycles.{view,create}` | 5 |
| GET, PATCH | `/compensation-cycles/{id}` | `compensation.cycles.{view,update}` | 5 |
| POST | `/compensation-cycles/{id}/{open,submit,approve,apply,close}` | `compensation.cycles.{open,submit,approve,apply,close}` | 5 |
| GET, POST | `/compensation-proposals` | `compensation.proposals.{view,create}` | 5 |
| GET, PATCH | `/compensation-proposals/{id}` | `compensation.proposals.{view,update}` | 5 |
| POST | `/compensation-proposals/{id}/{submit,approve,reject}` | `compensation.proposals.{submit,approve,reject}` | 5 |
| GET, POST | `/pay-equity-categories` | `compensation.pay_equity.{view,create}` | 5 |
| GET, PATCH | `/pay-equity-categories/{id}` | `compensation.pay_equity.{view,update}` | 5 |
| GET | `/pay-equity/report` | `compensation.pay_equity.view` | 5 |

**BNF · Beneficios**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/benefits` | `benefits.benefits.{view,create}` | 5 |
| GET, PATCH | `/benefits/{id}` | `benefits.benefits.{view,update}` | 5 |
| POST | `/benefits/{id}/archive` | `benefits.benefits.archive` | 5 |
| GET, POST | `/benefit-enrollments` | `benefits.enrollments.{view,create}` | 5 |
| GET | `/benefit-enrollments/{id}` | `benefits.enrollments.view` | 5 |
| POST | `/benefit-enrollments/{id}/{approve,reject,end}` | `benefits.enrollments.{approve,reject,end}` | 5 |

### Fase 6 · Experiencia


**ENC · Encuestas y clima**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/surveys` | `engagement.surveys.{view,create}` | 6 |
| GET, PATCH | `/surveys/{id}` | `engagement.surveys.{view,update}` | 6 |
| POST | `/surveys/{id}/{launch,close}` | `engagement.surveys.{launch,close}` | 6 |
| GET, POST | `/action-plans` | `engagement.action_plans.{view,create}` | 6 |
| GET, PATCH | `/action-plans/{id}` | `engagement.action_plans.{view,update}` | 6 |
| POST | `/action-plans/{id}/complete` | `engagement.action_plans.complete` | 6 |
| POST | `/surveys/{id}/responses` | sesión | 6 |
| GET | `/surveys/{id}/results` | `engagement.surveys.results` | 6 |

**REC · Reconocimientos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/recognitions` | `engagement.recognitions.{view,create}` | 6 |
| GET | `/recognitions/{id}` | `engagement.recognitions.view` | 6 |
| POST | `/recognitions/{id}/hide` | `engagement.recognitions.hide` | 6 |

**CIN · Comunicación interna**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/announcements` | `engagement.announcements.{view,create}` | 6 |
| GET, PATCH | `/announcements/{id}` | `engagement.announcements.{view,update}` | 6 |
| POST | `/announcements/{id}/{publish,archive,acknowledge}` | `engagement.announcements.{publish,archive,acknowledge}` | 6 |

**HSD · Mesa de ayuda**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/tickets` | `helpdesk.tickets.{view,create}` | 6 |
| GET, PATCH | `/tickets/{id}` | `helpdesk.tickets.{view,update}` | 6 |
| POST | `/tickets/{id}/{assign,request-info,resolve,close,reopen}` | `helpdesk.tickets.{assign,request_info,resolve,close,reopen}` | 6 |

### Fase 7 · Operación y cumplimiento


**SST · Seguridad y salud**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/sst/incidents` | `sst.incidents.{view,create}` | 7 |
| GET, PATCH | `/sst/incidents/{id}` | `sst.incidents.{view,update}` | 7 |
| POST | `/sst/incidents/{id}/{investigate,close}` | `sst.incidents.{investigate,close}` | 7 |
| GET, POST | `/sst/risks` | `sst.risks.{view,create}` | 7 |
| GET, PATCH | `/sst/risks/{id}` | `sst.risks.{view,update}` | 7 |
| POST | `/sst/risks/{id}/archive` | `sst.risks.archive` | 7 |
| GET, POST | `/sst/inspections` | `sst.inspections.{view,create}` | 7 |
| GET, PATCH | `/sst/inspections/{id}` | `sst.inspections.{view,update}` | 7 |
| POST | `/sst/inspections/{id}/close` | `sst.inspections.close` | 7 |
| GET, POST | `/sst/medical-exams` | `sst.medical_exams.{view,create}` | 7 |
| GET, PATCH | `/sst/medical-exams/{id}` | `sst.medical_exams.{view,update}` | 7 |
| GET, POST | `/sst/committees` | `sst.committees.{view,create}` | 7 |
| GET, PATCH | `/sst/committees/{id}` | `sst.committees.{view,update}` | 7 |
| GET, POST | `/sst/elections` | `sst.elections.{view,create}` | 7 |
| GET | `/sst/elections/{id}` | `sst.elections.view` | 7 |
| POST | `/sst/elections/{id}/{communicate,call,form-board,open-nominations,publish-candidates,open-voting,count,proclaim,install,annul}` | `sst.elections.{communicate,call,form_board,open_nominations,publish_candidates,open_voting,count,proclaim,install,annul}` | 7 |
| GET, POST | `/sst/committee-meetings` | `sst.committee_meetings.{view,create}` | 7 |
| GET, PATCH | `/sst/committee-meetings/{id}` | `sst.committee_meetings.{view,update}` | 7 |
| POST | `/sst/committee-meetings/{id}/{call,record-session,record-no-quorum,draft-minutes,sign-minutes,cancel}` | `sst.committee_meetings.{call,record_session,record_no_quorum,draft_minutes,sign_minutes,cancel}` | 7 |
| GET, POST | `/sst/corrective-actions` | `sst.corrective_actions.{view,create}` | 7 |
| GET, PATCH | `/sst/corrective-actions/{id}` | `sst.corrective_actions.{view,update}` | 7 |
| POST | `/sst/corrective-actions/{id}/{complete,verify}` | `sst.corrective_actions.{complete,verify}` | 7 |
| POST | `/sst/elections/{id}/votes` | `sst.elections.vote` | 7 |

**DEN · Denuncias y hostigamiento**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/grievances` | `grievances.cases.view` | 7 |
| GET | `/grievances/{id}` | `grievances.cases.view` | 7 |
| POST | `/grievances/{id}/{admit,assign,add-protection-measure,resolve,close,dismiss,offer-care,refer-to-committee,start-investigation,issue-report,decide}` | `grievances.cases.{admit,assign,add_protection_measure,resolve,close,dismiss,offer_care,refer_to_committee,start_investigation,issue_report,decide}` | 7 |
| GET, POST | `/harassment-committees` | `grievances.harassment_committees.{view,create}` | 7 |
| GET, PATCH | `/harassment-committees/{id}` | `grievances.harassment_committees.{view,update}` | 7 |
| POST | `/public/grievances` | público | 7 |
| GET | `/public/grievances/{tracking_code}` | público | 7 |
| POST | `/public/grievances/{tracking_code}/messages` | público | 7 |

**DIS · Casos disciplinarios**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/disciplinary-cases` | `discipline.cases.{view,create}` | 7 |
| GET, PATCH | `/disciplinary-cases/{id}` | `discipline.cases.{view,update}` | 7 |
| POST | `/disciplinary-cases/{id}/{notify,record-statement,resolve,close}` | `discipline.cases.{notify,record_statement,resolve,close}` | 7 |

**ACT · Activos y EPP**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/assets` | `assets.assets.{view,create}` | 7 |
| GET, PATCH | `/assets/{id}` | `assets.assets.{view,update}` | 7 |
| POST | `/assets/{id}/{assign,return,send-to-maintenance,report-lost,retire}` | `assets.assets.{assign,return,send_to_maintenance,report_lost,retire}` | 7 |
| GET, POST | `/ppe-deliveries` | `assets.ppe_deliveries.{view,create}` | 7 |
| GET | `/ppe-deliveries/{id}` | `assets.ppe_deliveries.view` | 7 |
| POST | `/ppe-deliveries/{id}/sign` | `assets.ppe_deliveries.sign` | 7 |

**GAS · Gastos y viáticos**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/expense-policies` | `expenses.policies.{view,create}` | 7 |
| GET, PATCH | `/expense-policies/{id}` | `expenses.policies.{view,update}` | 7 |
| POST | `/expense-policies/{id}/archive` | `expenses.policies.archive` | 7 |
| GET, POST | `/expense-reports` | `expenses.reports.{view,create}` | 7 |
| GET, PATCH | `/expense-reports/{id}` | `expenses.reports.{view,update}` | 7 |
| POST | `/expense-reports/{id}/{submit,approve,reject,mark-paid}` | `expenses.reports.{submit,approve,reject,mark_paid}` | 7 |
| GET, POST | `/travel-advances` | `expenses.advances.{view,create}` | 7 |
| GET | `/travel-advances/{id}` | `expenses.advances.view` | 7 |
| POST | `/travel-advances/{id}/{approve,reject,mark-paid,settle}` | `expenses.advances.{approve,reject,mark_paid,settle}` | 7 |

**CUM · Cumplimiento Perú**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/compliance/obligations` | `compliance.obligations.view` | 7 |
| GET, PATCH | `/compliance/obligations/{id}` | `compliance.obligations.{view,update}` | 7 |
| POST | `/compliance/obligations/{id}/{attach-evidence,reevaluate}` | `compliance.obligations.{attach_evidence,reevaluate}` | 7 |
| GET, POST | `/compliance/inspection-files` | `compliance.inspection_files.{view,create}` | 7 |
| GET | `/compliance/inspection-files/{id}` | `compliance.inspection_files.view` | 7 |
| GET, POST | `/compliance/sunafil-notices` | `compliance.notices.{view,create}` | 7 |
| GET, PATCH | `/compliance/sunafil-notices/{id}` | `compliance.notices.{view,update}` | 7 |
| POST | `/compliance/sunafil-notices/{id}/{assign,answer,close}` | `compliance.notices.{assign,answer,close}` | 7 |
| GET | `/compliance/ledger-entries` | `compliance.ledger.view` | 7 |
| GET | `/compliance/ledger-entries/{id}` | `compliance.ledger.view` | 7 |
| GET | `/compliance/deadlines` | `compliance.deadlines.view` | 7 |
| GET | `/compliance/deadlines/{id}` | `compliance.deadlines.view` | 7 |
| GET | `/compliance/authority-communications` | `compliance.authority_communications.view` | 7 |
| GET, PATCH | `/compliance/authority-communications/{id}` | `compliance.authority_communications.{view,update}` | 7 |
| POST | `/compliance/authority-communications/{id}/mark-sent` | `compliance.authority_communications.mark_sent` | 7 |
| GET, POST | `/compliance/evidence-exports` | `compliance.evidence_exports.{view,create}` | 7 |
| GET | `/compliance/evidence-exports/{id}` | `compliance.evidence_exports.view` | 7 |
| GET | `/compliance/board` | `compliance.obligations.view` | 7 |
| POST | `/compliance/ledger/verify` | `compliance.ledger.verify` | 7 |
| POST | `/compliance/mailbox-checks` | `compliance.notices.update` | 7 |

**PRI · Privacidad**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/privacy/consents` | `privacy.consents.{view,create}` | 7 |
| GET | `/privacy/consents/{id}` | `privacy.consents.view` | 7 |
| POST | `/privacy/consents/{id}/revoke` | `privacy.consents.revoke` | 7 |
| GET, POST | `/privacy/subject-requests` | `privacy.subject_requests.{view,create}` | 7 |
| GET, PATCH | `/privacy/subject-requests/{id}` | `privacy.subject_requests.{view,update}` | 7 |
| POST | `/privacy/subject-requests/{id}/{resolve,reject}` | `privacy.subject_requests.{resolve,reject}` | 7 |
| GET, POST | `/privacy/incidents` | `privacy.incidents.{view,create}` | 7 |
| GET, PATCH | `/privacy/incidents/{id}` | `privacy.incidents.{view,update}` | 7 |
| POST | `/privacy/incidents/{id}/{contain,mark-notified,close}` | `privacy.incidents.{contain,mark_notified,close}` | 7 |
| GET, POST | `/privacy/retention-policies` | `privacy.retention_policies.{view,create}` | 7 |
| GET, PATCH | `/privacy/retention-policies/{id}` | `privacy.retention_policies.{view,update}` | 7 |
| POST | `/privacy/people/{person_id}/anonymize` | `privacy.people.anonymize` | 7 |

**SIN · Sindicatos y convenios**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/unions` | `unions.unions.{view,create}` | 7 |
| GET, PATCH | `/unions/{id}` | `unions.unions.{view,update}` | 7 |
| POST | `/unions/{id}/archive` | `unions.unions.archive` | 7 |
| GET, POST | `/union-memberships` | `unions.memberships.{view,create}` | 7 |
| GET, PATCH | `/union-memberships/{id}` | `unions.memberships.{view,update}` | 7 |
| GET, POST | `/collective-agreements` | `unions.agreements.{view,create}` | 7 |
| GET, PATCH | `/collective-agreements/{id}` | `unions.agreements.{view,update}` | 7 |
| POST | `/collective-agreements/{id}/activate` | `unions.agreements.activate` | 7 |

### Fase 8 · Inteligencia


**ANA · People analytics**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/dashboards` | `analytics.dashboards.{view,create}` | 8 |
| GET, PATCH, DELETE | `/dashboards/{id}` | `analytics.dashboards.{view,update,delete}` | 8 |
| GET | `/analytics/metrics` | `analytics.metrics.view` | 8 |
| POST | `/analytics/query` | `analytics.metrics.view` | 8 |

**RPT · Reportes**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/reports` | `reports.reports.{view,create}` | 8 |
| GET, PATCH, DELETE | `/reports/{id}` | `reports.reports.{view,update,delete}` | 8 |
| POST | `/reports/{id}/run` | `reports.reports.run` | 8 |

**ALE · Alertas**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/alert-rules` | `alerts.rules.{view,create}` | 8 |
| GET, PATCH | `/alert-rules/{id}` | `alerts.rules.{view,update}` | 8 |
| POST | `/alert-rules/{id}/{pause,resume}` | `alerts.rules.{pause,resume}` | 8 |
| GET | `/alerts` | `alerts.alerts.view` | 8 |
| GET | `/alerts/{id}` | `alerts.alerts.view` | 8 |
| POST | `/alerts/{id}/{snooze,dismiss,resolve}` | `alerts.alerts.{snooze,dismiss,resolve}` | 8 |
| GET | `/analytics/turnover-risk` | `analytics.turnover_risk.view` | 8 |

**IAR · Asistente de IA**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/ai/conversations` | `ai.conversations.{view,create}` | 8 |
| GET | `/ai/conversations/{id}` | `ai.conversations.view` | 8 |
| POST | `/ai/conversations/{id}/send-message` | `ai.conversations.send_message` | 8 |
| GET | `/ai/usage-logs` | `ai.usage_logs.view` | 8 |
| GET | `/ai/usage-logs/{id}` | `ai.usage_logs.view` | 8 |
| GET | `/ai/use-case-settings` | `ai.use_case_settings.view` | 8 |
| GET, PATCH | `/ai/use-case-settings/{id}` | `ai.use_case_settings.{view,update}` | 8 |
| POST | `/ai/help-assistant/ask` | `ai.help_assistant.use` | 8 |
| POST | `/ai/help-assistant/answers/{id}/feedback` | `ai.help_assistant.use` | 8 |
| GET | `/ai/help-assistant/availability` | sesión | 8 |

### Fase 9 · Ecosistema y expansión


**API · API pública y webhooks**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/webhook-endpoints` | `integrations.webhooks.{view,create}` | 9 |
| GET, PATCH, DELETE | `/webhook-endpoints/{id}` | `integrations.webhooks.{view,update,delete}` | 9 |
| POST | `/webhook-endpoints/{id}/{rotate-secret,test,pause,resume}` | `integrations.webhooks.{rotate_secret,test,pause,resume}` | 9 |
| GET | `/webhook-deliveries` | `integrations.webhook_deliveries.view` | 9 |
| GET | `/webhook-deliveries/{id}` | `integrations.webhook_deliveries.view` | 9 |
| POST | `/webhook-deliveries/{id}/retry` | `integrations.webhook_deliveries.retry` | 9 |
| GET | `/events` | `integrations.events.view` | 9 |
| POST | `/mcp` | `ai.mcp.use` | 9 |

**INT · Integraciones**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/integrations` | `integrations.connections.{view,create}` | 9 |
| GET, PATCH | `/integrations/{id}` | `integrations.connections.{view,update}` | 9 |
| POST | `/integrations/{id}/{test,disconnect,sync}` | `integrations.connections.{test,disconnect,sync}` | 9 |
| GET | `/integrations/health` | `integrations.connections.view` | 9 |
| POST | `/integrations/biometric/{device_id}/punches` | `integrations.biometric.push` | 9 |

**WHA · WhatsApp**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| POST | `/channels/whatsapp/webhook` | público | 9 |
| GET | `/channels/whatsapp/settings` | `integrations.whatsapp.view` | 9 |
| PUT | `/channels/whatsapp/settings` | `integrations.whatsapp.update` | 9 |

**PAI · Paquetes de país**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/country-packs` | `platform.country_packs.view` | 9 |
| GET | `/country-packs/{id}` | `platform.country_packs.view` | 9 |
| POST | `/country-packs/{id}/activate` | `platform.country_packs.activate` | 9 |

**WLB · Marca blanca**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET | `/branding` | `settings.branding.view` | 9 |
| PUT | `/branding` | `settings.branding.update` | 9 |

**EST · Modo estudio contable**

| Método | Ruta | Permiso | Fase |
|---|---|---|---|
| GET, POST | `/partner/clients` | `partner.clients.{view,create}` | 9 |
| GET | `/partner/clients/{id}` | `partner.clients.view` | 9 |
| POST | `/partner/clients/{id}/enter` | `partner.clients.enter` | 9 |

<!-- ENDPOINTS:FIN -->

### Recurso de API → tabla

Los nombres de recurso de la API son del contrato (`openapi.yaml`) y pueden no coincidir con la tabla. Cuando difieren, la tabla de `database/schema.sql` es esta:

| Recurso de API | Tabla(s) en `schema.sql` |
|---|---|
| `absences` | `absence_requests` |
| `accounting-entries` | `journal_entries`, `journal_entry_lines` |
| `action-plans` | `survey_action_plans`, `survey_action_items` |
| `announcements` | `posts` |
| `approval-flows`, `workflows` | `workflow_definitions`, `workflow_definition_versions`, `workflow_steps`, `workflow_transitions` |
| `approval-requests` | `workflow_instances`, `workflow_tasks`, `workflow_decisions` |
| `workflow-runs` | `workflow_instances` (procesos) y `automation_runs` (reglas) |
| `audit-logs` | `audit_events` |
| `sensitive-access-logs` | `data_access_logs` |
| `benefit-runs` | `payroll_periods` (por tipo), `cts_deposits`, `profit_sharing_distributions` |
| `branding` | `tenant_brandings` |
| `compensation-cycles` | `compensation_review_cycles` |
| `compensation-proposals` | `compensation_review_proposals` |
| `contract-addenda` | `contract_amendments` |
| `custom-fields` | `custom_field_definitions`, `custom_field_values` |
| `declaration-files` | `statutory_filings`, `statutory_filing_files` |
| `events` | `calendar_events` |
| `exports` | `export_jobs` |
| `feedback` | `feedback_entries`, `feedback_requests` |
| `files` | `stored_files`, `attachments` |
| `geofences` | `attendance_geofences` |
| `grievances` | `complaints` y tablas `complaint_*` |
| `headcount` | `headcount_plans`, `headcount_scenarios`, `headcount_plan_lines` |
| `imports` | `import_jobs`, `import_job_rows` |
| `objectives` | `goals`, `key_results`, `goal_checkins` |
| `offboarding-processes` | `terminations` + `onboarding_processes` con `kind = offboarding` |
| `offers` | `job_offers` |
| `one-on-ones` | `one_on_one_series`, `one_on_one_meetings`, `one_on_one_items` |
| `pay-equity`, `pay-equity-categories` | `pay_equity_reports`, `pay_equity_report_lines`, `job_categories`, `salary_policies` |
| `payroll-regimes` | `labor_regimes`, `legal_entity_regimes`, `employment_labor_regimes` |
| `payroll-results` | `payroll_run_employments`, `payroll_run_lines` |
| `process-templates` | `onboarding_templates`, `onboarding_template_tasks` |
| `provisions` | `provision_runs`, `provision_lines` |
| `punch-devices` | `attendance_devices` |
| `time-punches` | `attendance_punches` |
| `review-cycles` | `performance_cycles`, `performance_cycle_participants` |
| `scorecards` | `scorecard_templates`, `interview_scorecards` |
| `settings` | `tenant_settings` (definiciones en `parameter_definitions`) |
| `setup-checklist` | `setup_checklist_items` |
| `talent-reviews` | `calibration_sessions`, `talent_assessments` |
| `tasks` | `hr_tasks` |
| `time-bank-movements` | `time_bank_entries` |
| `travel-advances` | `expense_advances` |
| `vacation-balances` | sin tabla de saldo: se calcula de `vacation_ledger_entries` y `vacation_accrual_periods` |
| `trash` | sin tabla: filas con `deleted_at` |
| `inbox`, `my-team`, `directory`, `org-chart`, `search` | sin tabla propia: vistas sobre `workflow_tasks`, `notifications`, `employment_assignments`, `positions` y `search_documents` |

Campos que difieren: la API expone `first_name` y `last_name`; en la base son `people.first_names` y `people.paternal_surname` + `people.maternal_surname`. Los estados viajan con el valor de base (inglés, `snake_case`; ver la conciliación al final de `05-maquinas-de-estado.md`).

## 13. Qué no cubre la tabla

- Los campos de cada acción y las guardas de cada transición: están en la especificación de cada módulo (`modulos/`).
- Sub-recursos de detalle fino (comentarios, adjuntos, participantes): siguen el mismo patrón `GET/POST /recurso/{id}/sub-recurso` y se añaden al generador cuando se implementa la historia.
- UI y QA no tienen endpoints propios.
