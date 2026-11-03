# M-TEN · Multiempresa y aislamiento

Fase 0 · Cimientos · Épica `TEN` · Reglas `RN-TEN-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Garantizar que cada organización (tenant) vea solo sus datos y que, dentro de ella, varias empresas (razones sociales) de distintos países convivan con su propia configuración.

## 2. Alcance

- Jerarquía Plataforma → Organización → Empresas (razón social, país, régimen) → Sedes → Unidades → Posiciones → Relaciones laborales.
- Aislamiento por `tenant_id` en todas las tablas, seguridad a nivel de fila y claves foráneas compuestas.
- Selector de empresa en la interfaz y consolidación de grupo.
- Usuarios que pertenecen a más de una organización (estudios contables, consultores) con cambio explícito de contexto.

## 3. Fuera de alcance

- Planes y facturación del SaaS (M-SAS). Estructura interna de la empresa (M-ORG).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §1 Arquitectura SaaS multitenant; §4 Estructura organizacional; §79 Configuración por empresa.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Administrador empresa: administra empresas del grupo. Superadmin SaaS: crea organizaciones. El resto opera dentro del contexto activo.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `org.legal_entities.view` | Ver empresas (razones sociales) | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `org.legal_entities.archive` | Archivar empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.create` | Crear empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.restore` | Restaurar empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.update` | Editar empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.view_consolidated` **(+)** | Ver información consolidada de todas las empresas del grupo | Administrador empresa, RRHH, Auditor |
| `settings.organization.view` | Ver la configuración de la organización y de cada empresa | Administrador empresa, RRHH, Auditor |
| `settings.organization.update` | Editar la configuración de la organización y de cada empresa | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Resolución de contexto.** 1) El usuario se autentica. 2) Si tiene acceso a varias organizaciones, elige una. 3) Toda petición lleva la organización activa; la base aplica la política de fila con ese valor. 4) El filtro de empresa de la barra superior acota listados y tableros.

**Alta de empresa en el grupo.** 1) El administrador registra razón social, identificador fiscal, país, moneda, zona horaria y régimen laboral por defecto. 2) El sistema activa el paquete de país y siembra catálogos (feriados, conceptos, tipos de contrato). 3) Queda disponible para asignar sedes y posiciones.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Selector de organización | Tras el ingreso, lista de organizaciones a las que el usuario pertenece | Prototipo: Ingreso (selector de organización y de usuario demo) |
| Selector de empresa | Filtro global por razón social o «Todas» | Prototipo: Barra superior → selector de empresa |
| Empresas del grupo | Tabla de razones sociales con país, régimen, moneda, estado; crear, editar, archivar | Prototipo: Organización → Estructura → pestaña Empresas |

## 8. Datos principales

Organizaciones, empresas (razón social, identificador fiscal, país, moneda, zona horaria, régimen por defecto, tamaño declarado, actividad económica), pertenencia usuario–organización.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección TEN.

- **RN-TEN-01**: Si una consulta o escritura no lleva organización activa → la base no devuelve ni modifica filas.
- **RN-TEN-02**: Si un registro referencia a otro de distinta organización → la clave foránea compuesta lo impide.
- **RN-TEN-03**: Si un endpoint nuevo se publica sin la prueba automática de aislamiento → la integración continua falla.
- **RN-TEN-04**: Si un usuario pertenece a varias organizaciones → cada sesión opera sobre una sola; cambiar exige elegir de nuevo y queda en actividad.
- **RN-TEN-05**: Si una empresa tiene relaciones laborales, periodos de planilla o documentos → no se elimina; solo se archiva y deja de aceptar altas.
- **RN-TEN-06**: Si una persona tiene relaciones laborales en dos empresas del mismo grupo → se ve una sola persona con dos relaciones, cada una con su régimen y planilla.
- **RN-TEN-07** (parámetro): Si la organización alcanza el máximo de empresas de su plan (límite del plan) → no se puede crear otra hasta ampliar el plan.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `company.created`, `company.archived`.

**Consume:**

- `tenant.suspended` / `tenant.reactivated` (M-SAS): ajusta el modo de acceso.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Trabajos en segundo plano y reportes programados deben fijar la organización antes de consultar.
- Archivos: la ruta de almacenamiento incluye la organización y se valida al firmar el enlace.
- Un soporte de la plataforma que entra a una organización lo hace por suplantación controlada (M-SAS), nunca por consulta directa.

## 14. Criterios de aceptación generales

- Una batería automática recorre todos los endpoints con credenciales de la organización A pidiendo recursos de B y siempre obtiene «no encontrado».
- Crear una empresa de otro país no altera cálculos ni catálogos de las existentes.
