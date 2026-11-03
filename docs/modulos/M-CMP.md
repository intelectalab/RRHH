# M-CMP · Competencias y habilidades

Fase 4 · Talento · Épica `CMP` · Reglas `RN-CMP-nn` · Módulo comercial: Desempeño y objetivos (`performance`)

## 1. Objetivo

Mantener el lenguaje común de talento: qué competencias y habilidades existen, qué nivel exige cada puesto y cuál tiene cada persona.

## 2. Alcance

- Catálogo de competencias (liderazgo, comunicación, trabajo en equipo, orientación a resultados, innovación, organización, otras) con niveles configurables y conductas observables.
- Habilidades técnicas (skills) con nivel, evidencia y validación; matriz de habilidades por equipo.
- Perfil de puesto: competencias y niveles requeridos.
- Brechas por persona frente a su puesto y frente a un puesto objetivo.
- Fuentes del nivel: evaluación, validación del jefe, certificación, curso completado.

## 3. Fuera de alcance

- Evaluación (M-DES). Rutas y sucesión (M-CAR).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §39 Competencias; §44 Plan de carrera.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-14 Movilidad interna, referidos y matriz de habilidades.

## 5. Actores y permisos

- RRHH administra el catálogo. Manager valida niveles. Colaborador declara habilidades.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `performance.competencies.view` | Ver competencias, niveles y perfiles de puesto | Administrador empresa, RRHH, Recruiter, Manager, Empleado, Auditor |
| `performance.competencies.archive` | Archivar competencias, niveles y perfiles de puesto | Administrador empresa, RRHH |
| `performance.competencies.create` | Crear competencias, niveles y perfiles de puesto | Administrador empresa, RRHH |
| `performance.competencies.update` | Editar competencias, niveles y perfiles de puesto | Administrador empresa, RRHH |
| `performance.competencies.view_levels` **(+)** | Ver niveles de competencias de personas dentro de su alcance | Administrador empresa, RRHH, Manager |
| `performance.skills.view` | Ver habilidades | Administrador empresa, RRHH, Recruiter, Manager, Empleado, Auditor |
| `performance.skills.archive` | Archivar habilidades | Administrador empresa, RRHH |
| `performance.skills.create` | Crear habilidades | Administrador empresa, RRHH |
| `performance.skills.update` | Editar habilidades | Administrador empresa, RRHH |
| `performance.skills.declare` **(+)** | Declarar habilidades propias | solo sobre lo propio o asignado: Empleado, Manager |
| `performance.skills.validate` **(+)** | Validar habilidades de su equipo | Administrador empresa, RRHH, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Perfil.** 1) RRHH asigna competencias y nivel requerido a cada puesto. 2) Tras una evaluación o validación se registra el nivel actual de la persona. 3) El sistema calcula brechas y las ofrece al plan de desarrollo.

**Habilidad.** 1) El colaborador declara una habilidad con nivel y evidencia. 2) El jefe valida. 3) Aparece en la matriz y en la búsqueda de movilidad interna.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Competencias | Catálogo con niveles y conductas; crear, archivar | Prototipo: Talento → Competencias |
| Perfiles de puesto | Competencias y nivel requerido por puesto | Prototipo: Talento → Competencias → pestaña Por puesto |
| Matriz de habilidades | Personas × habilidades con nivel; filtros por unidad | Prototipo: Talento → Competencias → pestaña Matriz de habilidades |
| Competencias del colaborador | Nivel actual frente a requerido y brechas | Prototipo: Personas → Colaboradores → Ficha → pestaña Competencias |

## 8. Datos principales

Competencias (`competencies`), niveles, requisitos por puesto, niveles por persona con fuente y fecha, habilidades y validaciones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CMP.

- **RN-CMP-01**: Si una competencia está en uso en perfiles o evaluaciones → no se elimina; se archiva.
- **RN-CMP-02**: Si se registra un nivel de persona → guarda fuente, fecha y autor; el nivel vigente es el más reciente de la fuente de mayor jerarquía definida.
- **RN-CMP-03**: Si se calcula la brecha → es nivel requerido menos nivel actual por competencia; sin nivel actual se muestra «sin evaluar», no cero.
- **RN-CMP-04**: Si el colaborador declara una habilidad → queda «declarada» hasta que su jefe la valide.
- **RN-CMP-05**: Si cambia la escala de niveles de una competencia → los niveles históricos conservan la escala con la que se registraron.
- **RN-CMP-06** (parámetro): Si una validación de habilidad supera su vigencia (parámetro por habilidad) → se marca «por revalidar».

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `competency.level_recorded`, `skill.declared`, `skill.validated`, `job_profile.requirements_changed`.

**Consume:**

- `review.published`, `course.completed`, `employment.assignment_changed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Persona que cambia de puesto: las brechas se recalculan contra el nuevo perfil.
- Competencia evaluada con escalas distintas en dos campañas.

## 14. Criterios de aceptación generales

- Las competencias con niveles se relacionan con los puestos y la ficha muestra las brechas.
