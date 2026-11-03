# M-CAR · Desarrollo, carrera, sucesión y 9-box

Fase 5 · Desarrollo y compensación · Épica `CAR` · Reglas `RN-CAR-nn` · Módulo comercial: Carrera y sucesión (`careers`)

## 1. Objetivo

Apoyar las decisiones de talento: planes de desarrollo, rutas de carrera, sucesión de puestos críticos y matriz 9-box, siempre como insumo para decisiones humanas.

## 2. Alcance

- Planes de desarrollo individual con competencia, nivel actual y objetivo, acciones (curso, mentoría, proyecto, seguimiento), responsable y fecha.
- Rutas de carrera entre puestos con competencias requeridas y brechas.
- Sucesión: posiciones críticas, candidatos sucesores con preparación (preparado ahora, 1 año, 2 años, en desarrollo), riesgo de pérdida, matriz de sucesión.
- 9-box: desempeño × potencial, por sesión de talento, con historial.
- Riesgo de rotación explicable (factores visibles).

## 3. Fuera de alcance

- Promoción efectiva (movimiento en M-HIS con aprobación). Cursos (M-LMS).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §43 Planes de desarrollo; §44 Plan de carrera; §45 Sucesión; §46 Matriz 9-box.

**Correcciones que incorpora:** C-08 IA con gobierno.

**Ampliaciones que incorpora:** E-26 Riesgo de rotación explicable.

## 5. Actores y permisos

- RRHH conduce. Managers proponen y dan seguimiento. Colaborador ve su plan y sus rutas. Dirección revisa sucesión.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `careers.development_plans.view` | Ver planes de desarrollo | Administrador empresa, RRHH, Manager; solo sobre lo propio o asignado: Empleado |
| `careers.development_plans.activate` | Activar planes de desarrollo | Administrador empresa, RRHH, Manager |
| `careers.development_plans.complete` | Completar planes de desarrollo | Administrador empresa, RRHH, Manager |
| `careers.development_plans.create` | Crear planes de desarrollo | Administrador empresa, RRHH, Manager |
| `careers.development_plans.update` | Editar planes de desarrollo | Administrador empresa, RRHH, Manager |
| `careers.paths.view` | Ver rutas de carrera | Administrador empresa, RRHH, Manager, Empleado |
| `careers.paths.archive` | Archivar rutas de carrera | Administrador empresa, RRHH |
| `careers.paths.create` | Crear rutas de carrera | Administrador empresa, RRHH |
| `careers.paths.update` | Editar rutas de carrera | Administrador empresa, RRHH |
| `careers.succession_plans.view` **(S)** | Ver planes de sucesión | Administrador empresa, RRHH |
| `careers.succession_plans.archive` **(S)** | Archivar planes de sucesión | Administrador empresa, RRHH |
| `careers.succession_plans.create` **(S)** | Crear planes de sucesión | Administrador empresa, RRHH |
| `careers.succession_plans.update` **(S)** | Editar planes de sucesión | Administrador empresa, RRHH |
| `careers.talent_reviews.view` **(S)** | Ver sesiones de talento y matriz 9-box | Administrador empresa, RRHH |
| `careers.talent_reviews.close` **(S)** | Cerrar sesiones de talento y matriz 9-box | Administrador empresa, RRHH |
| `careers.talent_reviews.create` **(S)** | Crear sesiones de talento y matriz 9-box | Administrador empresa, RRHH |
| `careers.talent_reviews.update` **(S)** | Editar sesiones de talento y matriz 9-box | Administrador empresa, RRHH |
| `analytics.turnover_risk.view` **(S)** | Ver indicadores de riesgo de rotación | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Plan de desarrollo.** 1) Tras la evaluación, jefe y colaborador eligen competencias. 2) Definen nivel objetivo y acciones. 3) Las acciones de curso crean inscripciones. 4) Se revisa el avance en los 1:1. 5) Se cierra con el nivel alcanzado.

**Sucesión.** 1) Se marcan posiciones críticas. 2) Se proponen sucesores con su preparación. 3) Se revisa en sesión de talento. 4) Se definen acciones de desarrollo para cerrar brechas.

**9-box.** 1) Se abre la sesión para una población. 2) El sistema propone el eje de desempeño desde la última evaluación; el potencial lo valora el comité. 3) Se ubica a cada persona con sustento. 4) Se guardan la matriz y las acciones acordadas.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Desarrollo | Planes por persona con avance; crear | Prototipo: Talento → Desarrollo y sucesión |
| Rutas de carrera | Mapa de puestos y pasos con requisitos y brechas | Prototipo: Talento → Desarrollo y sucesión |
| Sucesión | Posiciones críticas, sucesores y preparación; matriz de sucesión | Prototipo: Talento → Desarrollo y sucesión |
| Matriz 9-box | Cuadrícula con personas; filtros; historial por sesión | Prototipo: Talento → Desarrollo y sucesión |
| Mi plan | Plan propio, acciones y rutas posibles | Prototipo: Talento → Desarrollo y sucesión |

## 8. Datos principales

Planes de desarrollo y acciones, rutas y pasos, posiciones críticas, candidatos a sucesión, sesiones de talento, ubicaciones 9-box, indicadores de riesgo con factores.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CAR.

- **RN-CAR-01**: Si una persona se ubica en la matriz 9-box → no se produce ningún cambio automático en sueldo, puesto ni estado; toda acción posterior requiere decisión y aprobación humana.
- **RN-CAR-02**: Si se propone un sucesor → la persona propuesta no ve su condición de sucesor salvo que RRHH decida comunicarla.
- **RN-CAR-03**: Si se muestra una ruta de carrera → incluye las competencias requeridas del puesto destino y la brecha de la persona.
- **RN-CAR-04**: Si una acción del plan es un curso → crea la inscripción y su avance se refleja en el plan.
- **RN-CAR-05**: Si una posición crítica no tiene sucesor «preparado ahora» → aparece como riesgo en el tablero de sucesión.
- **RN-CAR-06**: Si se muestra un riesgo de rotación → se listan los factores que lo componen y su peso; nunca un puntaje sin explicación, y no dispara acciones automáticas.
- **RN-CAR-07** (parámetro): Si el modelo de riesgo usa datos personales para perfilar → requiere evaluación de impacto registrada antes de activarse (M-PRI).
- **RN-CAR-08**: Si la ubicación 9-box se modifica → se conserva el historial por sesión con autor y sustento.
- **RN-CAR-09**: Si el sucesor o el titular cesan o cambian de posición → el plan de sucesión se marca «por revisar».

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `development_plan.created`, `development_plan.action_added`, `development_plan.completed`, `succession.updated`, `talent_review.closed`.

**Consume:**

- `review.published`, `competency.level_recorded`, `course.completed`, `employee.terminated`, `position.vacated`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Persona nueva sin evaluación: «sin datos» en el eje de desempeño.
- Un mismo sucesor para varias posiciones.
- Ruta lateral (cambio de familia de puestos).

## 14. Criterios de aceptación generales

- Existen planes de desarrollo con acciones, rutas con brechas, matriz de sucesión con preparación y 9-box visual sin decisiones automáticas.
