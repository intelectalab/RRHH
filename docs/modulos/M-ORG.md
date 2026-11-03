# M-ORG · Estructura organizacional

Fase 1 · Core HR · Épica `ORG` · Reglas `RN-ORG-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Modelar la empresa tal como es (razones sociales, sedes, unidades, centros de costo, puestos de catálogo, niveles y categorías) y mostrarla en un organigrama que se actualiza solo.

## 2. Alcance

- Sedes, sucursales y ubicaciones (con datos de establecimiento para declaraciones, geocerca y marca de riesgo SCTR).
- Unidades organizativas en árbol de profundidad libre con tipo configurable (gerencia, área, departamento, subdepartamento, unidad, equipo) y responsable.
- Centros de costo (árbol, vigencia, distribución porcentual).
- Catálogo de puestos (`job_profiles`): nombre, familia, nivel, categoría laboral, descripción, funciones, requisitos, competencias, banda, marca de riesgo, marca de puesto de dirección o confianza.
- Niveles, cargos y categorías laborales (base del cuadro de categorías y funciones de la Ley 30709).
- Organigrama dinámico por personas y por unidades; directorio.
- Reorganizaciones con fecha de vigencia.

## 3. Fuera de alcance

- Posiciones, presupuesto y vacantes (M-POS). Bandas salariales (M-COM).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §1 Arquitectura SaaS multitenant; §4 Estructura organizacional.

**Correcciones que incorpora:** C-02 Puesto ≠ posición.

**Ampliaciones que incorpora:** E-05 Igualdad salarial (Ley 30709); E-10 Organigrama por posiciones.

## 5. Actores y permisos

- Administrador y RRHH administran. Todos consultan directorio y organigrama según permiso.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `org.legal_entities.view` | Ver empresas (razones sociales) | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `org.legal_entities.archive` | Archivar empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.create` | Crear empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.restore` | Restaurar empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.update` | Editar empresas (razones sociales) | Administrador empresa |
| `org.legal_entities.view_consolidated` **(+)** | Ver información consolidada de todas las empresas del grupo | Administrador empresa, RRHH, Auditor |
| `org.units.view` | Ver unidades organizativas | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `org.units.archive` | Archivar unidades organizativas | Administrador empresa, RRHH |
| `org.units.create` | Crear unidades organizativas | Administrador empresa, RRHH |
| `org.units.move` | Mover unidades organizativas | Administrador empresa, RRHH |
| `org.units.restore` | Restaurar unidades organizativas | Administrador empresa, RRHH |
| `org.units.update` | Editar unidades organizativas | Administrador empresa, RRHH |
| `org.sites.view` | Ver sedes | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `org.sites.archive` | Archivar sedes | Administrador empresa, RRHH |
| `org.sites.create` | Crear sedes | Administrador empresa, RRHH |
| `org.sites.restore` | Restaurar sedes | Administrador empresa, RRHH |
| `org.sites.update` | Editar sedes | Administrador empresa, RRHH |
| `org.cost_centers.view` | Ver centros de costo | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `org.cost_centers.archive` | Archivar centros de costo | Administrador empresa, RRHH |
| `org.cost_centers.create` | Crear centros de costo | Administrador empresa, RRHH |
| `org.cost_centers.restore` | Restaurar centros de costo | Administrador empresa, RRHH |
| `org.cost_centers.update` | Editar centros de costo | Administrador empresa, RRHH |
| `org.job_profiles.view` | Ver puestos de catálogo, niveles y categorías | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `org.job_profiles.archive` | Archivar puestos de catálogo, niveles y categorías | Administrador empresa, RRHH |
| `org.job_profiles.create` | Crear puestos de catálogo, niveles y categorías | Administrador empresa, RRHH |
| `org.job_profiles.restore` | Restaurar puestos de catálogo, niveles y categorías | Administrador empresa, RRHH |
| `org.job_profiles.update` | Editar puestos de catálogo, niveles y categorías | Administrador empresa, RRHH |
| `people.directory.view` | Ver el directorio y el organigrama | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Crear o mover una unidad.** 1) Se indica nombre, tipo, unidad superior, responsable, centro de costo y fecha de vigencia. 2) Al mover, el sistema muestra el impacto (personas, aprobaciones pendientes, alcance de managers). 3) Se confirma; el cambio rige desde la fecha indicada y el organigrama lo refleja ese día.

**Cambio de responsable.** 1) Se elige el nuevo responsable (una posición ocupada). 2) Se actualiza la línea de reporte de los integrantes que reportaban al anterior por ser jefe de unidad. 3) Se reasignan aprobaciones pendientes.

**Alta de puesto de catálogo.** 1) Se registra el puesto con nivel, categoría, funciones y requisitos. 2) La IA puede proponer un borrador de descripción que una persona edita y aprueba. 3) El puesto queda disponible para crear posiciones.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Estructura | Pestañas Empresas, Sedes, Unidades, Puestos (y Centros de costo, Niveles y categorías); tablas con crear, editar, archivar, ver archivados | Prototipo: Organización → Estructura |
| Organigrama | Árbol por personas o por unidades, búsqueda, plegar ramas, filtro por empresa; ir a la ficha | Prototipo: Personas → Organigrama |
| Directorio | Tarjetas de colaboradores con puesto, unidad, sede y contacto permitido; búsqueda | Prototipo: Personas → Directorio |
| Reorganización | Asistente para mover unidades o personas en bloque con fecha y vista previa de impacto | Prototipo: Organización → Estructura → Reorganizar |

## 8. Datos principales

Sedes (`locations`), unidades organizativas (`org_units`, árbol con vigencia), tipos de unidad, centros de costo (`cost_centers`), puestos de catálogo (`job_profiles`), niveles, familias, categorías laborales.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ORG.

- **RN-ORG-01**: Si una unidad tiene posiciones activas o subunidades → no se archiva hasta moverlas o cerrarlas.
- **RN-ORG-02**: Si se mueve una unidad bajo uno de sus propios descendientes → se rechaza (el árbol no admite ciclos).
- **RN-ORG-03**: Si cambia el responsable de una unidad o el jefe de una persona → el organigrama, el alcance de managers y los aprobadores se recalculan desde la fecha de vigencia sin intervención manual.
- **RN-ORG-04**: Si un puesto de catálogo tiene posiciones o historial → no se elimina; se archiva y no admite nuevas posiciones.
- **RN-ORG-05**: Si una sede tiene relaciones laborales activas → no se archiva.
- **RN-ORG-06**: Si la distribución de un centro de costo entre varios → los porcentajes deben sumar 100.
- **RN-ORG-07**: Si un puesto no tiene categoría laboral asignada → aparece como pendiente en el cuadro de categorías y funciones (M-COM) y en el tablero de cumplimiento.
- **RN-ORG-08**: Si el directorio se muestra a un Empleado → solo incluye nombre, puesto, unidad, sede y los datos de contacto laboral que la organización decidió publicar.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `org_unit.created`, `org_unit.moved`, `org_unit.manager_changed`, `org_unit.archived`, `job_profile.created`, `job_profile.updated`, `location.created`.

**Consume:**

- `employment.assignment_changed`, `position.filled`, `position.vacated` (para redibujar el organigrama).

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Unidad sin responsable: sus integrantes reportan al responsable de la unidad superior.
- Reorganización futura: el organigrama permite ver «a la fecha».
- Matriz: una persona con jefe funcional distinto del jerárquico (línea punteada, sin efecto en aprobaciones salvo configuración).

## 14. Criterios de aceptación generales

- Cambiar un jefe o un cargo actualiza organigrama, directorio y alcance sin pasos adicionales.
- La estructura admite los niveles razón social, sede, área, departamento, subdepartamento, unidad y equipo sin límite fijo de profundidad.
