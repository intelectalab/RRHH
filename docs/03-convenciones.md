# 03 · Convenciones

Estas convenciones son obligatorias. Donde una herramienta puede imponerlas (Pint, PHPStan, ESLint, pruebas de arquitectura), lo hace; el resto se revisa con `/revisar`.

## 1. Idioma

| Qué | Idioma |
|---|---|
| Código (clases, variables, tablas, columnas, rutas, eventos, permisos) | Inglés |
| Textos de interfaz, mensajes de error, notificaciones | Claves i18n; español de Perú como base |
| Documentación, comentarios de dominio, mensajes de commit, historias | Español |
| Términos legales peruanos sin traducción razonable | Se conservan en comentarios y textos (CTS, PLAME, AFP); en código, nombre inglés estable + código del paquete (`concept code: PE_CTS`) |

Vocabulario fijo código ↔ negocio (no usar sinónimos):

| Negocio | Código |
|---|---|
| Organización | `tenant` |
| Razón social / empresa | `legal_entity` |
| Sede | `site` |
| Unidad (área, departamento, equipo) | `org_unit` |
| Puesto de catálogo | `job_profile` |
| Posición (plaza) | `position` |
| Persona | `person` |
| Relación laboral | `employment` |
| Colaborador | `employee` solo en eventos públicos (`employee.hired`) y textos; en el modelo es `employment` |
| Usuario | `user` |
| Asignación con vigencia | `assignment` |
| Compensación | `compensation` |
| Marcación | `time_punch` |
| Periodo de planilla | `payroll_period` |
| Concepto | `payroll_concept` |
| Boleta | `payslip` |
| Liquidación | `settlement` |
| Vacante / requisición | `job_requisition` |
| Postulación | `application` |
| Denuncia | `grievance` |
| Locador | `contractor` |

## 2. Backend (Laravel 12 / PHP 8.4)

### 2.1 Estructura de un módulo

```
app/Modules/Time/
  Api/                         interfaz pública para otros módulos (contratos y DTO)
    VacationBalances.php
  Domain/
    VacationRequest.php        entidad o agregado (sin Eloquent)
    States/VacationRequestMachine.php
    Events/LeaveApproved.php
    Ports/…                    interfaces hacia fuera
    Rules/…                    reglas puras
  Application/
    Actions/ApproveVacationRequest.php
    Queries/ListVacationRequests.php
    Data/VacationRequestData.php
    Policies/VacationRequestPolicy.php
    Listeners/OnEmployeeHired.php
  Infrastructure/
    Models/VacationRequestModel.php
    Repositories/…
    Jobs/AccrueVacationJob.php
    Adapters/…
  Http/
    Controllers/VacationRequestController.php
    Requests/StoreVacationRequestRequest.php
    Resources/VacationRequestResource.php
    routes.php
  Database/Migrations · Factories · Seeders
  Tests/Unit · Feature · Golden
  lang/es · en · pt
  module.php                   clave de módulo comercial, permisos, eventos, fuentes de pendientes, alertas y búsqueda
```

### 2.2 Nombres

| Elemento | Convención | Ejemplo |
|---|---|---|
| Caso de uso de escritura | Verbo + sustantivo, clase invocable | `ApproveVacationRequest` |
| Consulta | `List…`, `Get…`, `Find…` | `ListEmployments` |
| Evento de dominio (clase) | Pasado | `LeaveApproved` |
| Evento (nombre público) | `agregado.hecho` | `leave.approved` |
| Trabajo en cola | Sustantivo + `Job` | `CalculatePayrollBatchJob` |
| Puerto | Sustantivo de capacidad | `SignatureProvider` |
| Adaptador | Proveedor + puerto | `FakeSignatureProvider` |
| Policy | Recurso + `Policy` | `EmploymentPolicy` |
| Permiso | `modulo.recurso.accion` | `time.vacation_requests.approve` |
| Permiso por campo | `modulo.recurso.campo.view\|edit` | `people.employments.salary.view` |
| Excepción de dominio | Hecho + `Exception`, con código de error | `InvalidTransitionException` |

### 2.3 Reglas de código

- `declare(strict_types=1);` en todo archivo. Tipos en parámetros, retornos y propiedades. PHPStan nivel máximo acordado en PLT, sin `@phpstan-ignore` sin comentario.
- Clases `final` por defecto; `readonly` en DTO y objetos de valor.
- Controladores: máximo validar, llamar a un caso de uso y devolver un Resource. Sin consultas.
- Un caso de uso = una transacción (`DB::transaction`) = un commit. No anidar casos de uso con transacciones propias; componer a nivel de dominio.
- Eloquent solo en `Infrastructure`. La capa de aplicación recibe y devuelve DTO o entidades.
- Prohibido: `DB::raw` con interpolación, `withoutGlobalScopes` fuera de `Shared/Tenancy`, `env()` fuera de `config/`, `now()` en dominio (usar `Clock` inyectado), `float` para dinero, `Cache::` directo (usar `TenantCache`), `Http::` fuera de adaptadores.
- Dinero: objeto de valor `Money` (decimal exacto + moneda). Sumar monedas distintas lanza excepción.
- Fechas: `CarbonImmutable`. `LocalDate` para fechas laborales.
- Enumeraciones PHP para estados y tipos cerrados; los catálogos configurables por el cliente son tablas.
- Configuración por organización en la tabla de ajustes, nunca en `.env`.
- Registro técnico: `Log::info('evento', [...contexto])` con claves; sin nombres, documentos, sueldos ni contenido de formularios.

### 2.4 Migraciones

- Una migración por cambio lógico, dentro del módulo dueño de la tabla. Nombre: `YYYY_MM_DD_HHMMSS_create_<tabla>_table` / `add_<col>_to_<tabla>`.
- Tablas en inglés, `snake_case`, plural. Columnas `snake_case`. Claves foráneas `<singular>_id`. Booleanos `is_…`/`has_…`. Fechas `…_on` (date) y `…_at` (timestamptz).
- Obligatorio en tablas de negocio: `tenant_id`, única `(tenant_id, id)`, FK compuestas, RLS activado y forzado, `created_at`, `updated_at`.
- Vigencia: `valid_from`, `valid_to`, exclusión GiST.
- Estados: `text` + `CHECK`. Sin tipos `enum` de Postgres (cambiarlos bloquea despliegues).
- Sin `ON DELETE CASCADE` en datos laborales. `RESTRICT` por defecto.
- Soft delete (`deleted_at`) en recursos que van a la papelera; las tablas de solo inserción no lo llevan.
- Despliegue sin corte: añadir columna nula → poblar por lotes → restringir. Nunca renombrar y usar en el mismo despliegue.
- Índices grandes con `CREATE INDEX CONCURRENTLY` (migración sin transacción).
- `down()` reversible; si no puede serlo, se explica en un comentario.
- Los datos de catálogo del país van en el paquete de país, no en migraciones.

### 2.5 Manejo de errores

- El dominio lanza excepciones tipadas que implementan `DomainError` (`code()`, `status()`, `meta()`).
- Un único manejador traduce a la envoltura de error (`08-api.md` §4). Los controladores no capturan excepciones.
- Validación de forma → `FormRequest` → 422 `validation_failed` con `details[]`.
- Regla de negocio → 422 `business_rule_violated` con `meta.rule`.
- Transición → 409 `invalid_transition`.
- Errores inesperados → 500 `internal_error` con `request_id`; el detalle va solo al log técnico.
- Fallo de proveedor externo dentro de una solicitud síncrona → 502 `upstream_unavailable`; lo normal es que el efecto externo sea asíncrono y la solicitud no falle.
- Mensajes para el usuario: traducidos, en lenguaje llano, dicen qué pasó y qué hacer. Nunca trazas ni nombres de tabla.

## 3. Frontend (Next.js 15 / TypeScript)

### 3.1 Estructura

```
apps/web/src/
  app/
    (app)/            aplicación de RRHH y manager (armazón con barra lateral)
    (portal)/         portal del colaborador (móvil primero)
    (plataforma)/     consola SaaS (dominio aparte)
    (publico)/        empleos, denuncias, firma por enlace
  modules/<modulo>/
    screens/          pantallas completas
    components/       componentes del módulo
    api.ts            funciones del cliente (tipos de packages/contracts)
    hooks.ts
  ui/                 sistema de diseño (tokens.css, componentes base)
  lib/                apiClient, auth, permisos (can, moduleOn), i18n, formato
messages/es.json · en.json · pt.json
```

### 3.2 Reglas

- TypeScript `strict`. Sin `any` salvo en fronteras con comentario. Tipos de API solo desde `packages/contracts` (generados); no se escriben a mano.
- Componentes de servidor para el armazón y la carga inicial; componentes de cliente para lo interactivo. Las escrituras van siempre a `/api/v1`; no se usan acciones de servidor con lógica de negocio.
- Datos remotos con una librería de caché de consultas; claves `[modulo, recurso, params]`; invalidación por recurso tras una escritura.
- Estado de filtros, orden, página y pestaña en la URL (enlaces compartibles, botón atrás).
- Formularios con esquema de validación derivado del contrato; los errores 422 del servidor se asignan al campo por `details[].field`.
- Permisos: `can('time.vacation_requests.approve')`, `moduleOn('payroll')`, y `available_actions` de la respuesta para botones de estado. Ocultar no es proteger.
- Campos sensibles: se pinta el campo si viene en la respuesta; si no viene, se omite la fila completa (sin «••••» que sugiera que existe y se puede forzar).
- Nada de negocio en `localStorage`/`sessionStorage`: solo preferencias de interfaz (tema, columnas, barra lateral plegada).
- Estilos: Tailwind con tokens (`bg-surface`, `text-ink`…). Prohibidos colores literales y valores arbitrarios (`bg-[#…]`). Regla de lint.
- Textos: `t('time.vacations.request.title')`. Regla de lint contra cadenas literales en JSX.
- Formato: `formatMoney(money)`, `formatDate(date, {tz})`, `formatNumber`. Nunca `toFixed` ni concatenar símbolos de moneda.
- Accesibilidad: ver `10-pantallas-y-ux.md` §9.

### 3.3 Nombres

| Elemento | Convención |
|---|---|
| Componentes | `PascalCase.tsx`, un componente exportado por archivo |
| Hooks | `useAlgo` |
| Rutas | `kebab-case`, en español en la URL visible (`/colaboradores/[id]`), alineadas con el menú |
| Claves i18n | `modulo.pantalla.elemento`, `snake_case` por segmento |
| Pruebas | `Algo.test.tsx` junto al archivo; e2e en `apps/web/e2e/<fase>/<flujo>.spec.ts` |

## 4. API: forma de la respuesta

Detalle completo en `08-api.md`. Resumen:

```json
{ "data": { "id": "…", "status": "pending" },
  "meta": { "available_actions": ["approve", "reject", "cancel"], "as_of": "2026-10-02" } }
```

```json
{ "data": [ … ], "meta": { "page": 1, "per_page": 25, "total": 312, "next_cursor": null } }
```

```json
{ "error": { "code": "validation_failed", "message": "Revisa los campos marcados.",
             "request_id": "…", "details": [ { "field": "ends_on", "rule": "after_or_equal", "message": "…" } ] } }
```

- Nombres de campo en `snake_case`. Identificadores opacos (texto). Fechas ISO 8601. Dinero `{amount: "3500.00", currency: "PEN"}`.
- Relaciones: se devuelve `x_id`; la expansión se pide con `include=` y está limitada a lo declarado.
- `null` explícito para «sin valor»; **ausencia** del campo significa «sin permiso para verlo».

## 5. Pruebas

Detalle en `11-pruebas-y-calidad.md`. Convenciones:

- Backend: Pest. Nombre en español que describe el comportamiento: `it('rechaza aprobar una solicitud ya aprobada')`.
- Estructura: preparar → actuar → afirmar. Una razón de fallo por prueba.
- Datos con factories; ayudantes `tenant()`, `actingAsRole('rrhh', scope: …)`, `otherTenant()`.
- Tiempo controlado con `Clock` falso; ninguna prueba depende de la fecha real.
- Adaptadores falsos siempre; ninguna prueba sale a red.
- Frontend: Vitest + Testing Library (por rol y texto accesible, no por clase CSS). E2E con Playwright.
- Casos dorados de planilla: archivos de datos, no código (ver §8 de `11`).

## 6. Git y commits

- Ramas: `<tipo>/<código-historia>-<resumen>` → `feat/VAC-3-aprobacion-vacaciones`.
- Commits convencionales, en español, con el código de historia:

```
feat(time): aprobar solicitud de vacaciones actualiza saldo [VAC-3]

- transición pending → approved por acción
- evento leave.approved en outbox
- pruebas de permiso, aislamiento y transición inválida
```

- Tipos: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `db` (migraciones), `sec`.
- Ámbito = módulo de dominio (`time`, `payroll`, `people`, `shared`, `web`, `country-pe`).
- Un cambio por commit; migración y código que la usa en el mismo cambio revisable.
- Toda solicitud de cambio pasa `make test lint` y la lista de `/revisar`. Describe: qué historia, qué criterios cubre, cómo se probó, qué decisiones abiertas toca.
- Nunca se confirman secretos, volcados de datos ni archivos con datos personales reales.

## 7. Documentación viva

- `api/openapi.yaml` se genera desde `tools/gen_openapi.py`; se edita el generador, no el YAML.
- Cambia el modelo → se actualizan `database/schema.sql` y `04-modelo-de-datos.md` en el mismo cambio.
- Una decisión abierta que se cierra se mueve a un registro de decisiones (ADR) en `docs/adr/NNNN-titulo.md` y se marca cerrada en `13-decisiones-abiertas.md`.
- Sin precios, costos, horas ni estimaciones en ningún documento ni comentario.
