# M-DES · Evaluación de desempeño

Fase 4 · Talento · Épica `DES` · Reglas `RN-DES-nn` · Módulo comercial: Desempeño y objetivos (`performance`)

## 1. Objetivo

Medir el desempeño con campañas configurables de 90° a 360°, con resultados útiles para desarrollo, compensación y talento.

## 2. Alcance

- Campañas con población, calendario, tipo (90°, 180°, 270°, 360°) y participantes (autoevaluación, jefe, pares, subordinados, otros).
- Formularios con competencias, objetivos, preguntas abiertas y escalas configurables; pesos por sección y por evaluador.
- Selección y aprobación de pares; anonimato de pares y subordinados.
- Calibración por comités; retroalimentación; firma o acuse del evaluado; plan de desarrollo.
- Evaluación de periodo de prueba; resultados para 9-box y revisión salarial.

## 3. Fuera de alcance

- Objetivos (M-OKR). Catálogo de competencias (M-CMP).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §38 Evaluación de desempeño.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH diseña y administra. Evaluadores completan. Manager calibra y da retroalimentación. Evaluado se autoevalúa y recibe resultados.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `performance.cycles.view` | Ver campañas de evaluación | Administrador empresa, RRHH, Manager, Auditor |
| `performance.cycles.close` | Cerrar campañas de evaluación | Administrador empresa, RRHH |
| `performance.cycles.create` | Crear campañas de evaluación | Administrador empresa, RRHH |
| `performance.cycles.launch` | Lanzar campañas de evaluación | Administrador empresa, RRHH |
| `performance.cycles.start_calibration` | Iniciar la calibración de campañas de evaluación | Administrador empresa, RRHH |
| `performance.cycles.update` | Editar campañas de evaluación | Administrador empresa, RRHH |
| `performance.reviews.view` | Ver evaluaciones asignadas y resultados propios publicados | solo sobre lo propio o asignado: todos los roles de organización |
| `performance.reviews.reopen` | Reabrir evaluaciones asignadas y resultados propios publicados | Administrador empresa, RRHH |
| `performance.reviews.share` | Publicar resultados de evaluaciones asignadas y resultados propios publicados | Administrador empresa, RRHH, Manager |
| `performance.reviews.submit` | Enviar evaluaciones asignadas y resultados propios publicados | solo sobre lo propio o asignado: todos los roles de organización |
| `performance.reviews.update` | Editar evaluaciones asignadas y resultados propios publicados | solo sobre lo propio o asignado: todos los roles de organización |
| `performance.reviews.calibrate` **(+)** | Ajustar calificaciones en sesiones de calibración | Administrador empresa, RRHH |
| `performance.review_templates.update` **(+)** | Diseñar formularios y escalas | Administrador empresa, RRHH |
| `performance.reviews.results.view` **(S)** **(+)** | Ver resultados de evaluación de otras personas dentro de su alcance | Administrador empresa, RRHH, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Campaña.** 1) RRHH configura población, formulario, evaluadores y fechas. 2) Lanza: se crean evaluaciones y se notifica. 3) Evaluados proponen pares; el jefe aprueba. 4) Cada evaluador completa. 5) Cierra la etapa de evaluación y se calculan resultados. 6) Calibración. 7) Publicación al jefe y luego al evaluado en la reunión de retroalimentación. 8) El evaluado da acuse y comenta. 9) Cierre y generación de planes de desarrollo.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Campañas de desempeño | Lista con etapa, avance por evaluador y por unidad; crear, lanzar | Prototipo: Talento → Evaluaciones |
| Seguimiento de campaña | Avance, pendientes, recordar, reasignar evaluador | Prototipo: Talento → Evaluaciones |
| Formulario de evaluación | Secciones, escala, comentarios, guardar y enviar | Prototipo: Talento → Evaluaciones |
| Calibración | Distribución por unidad, comparar, ajustar con motivo | Prototipo: Talento → Evaluaciones |
| Resultados | Resultado por persona, por competencia y por evaluador (según anonimato) | Prototipo: Personas → Colaboradores → Ficha → pestaña Desempeño |
| Formularios y escalas | Biblioteca de formularios | Prototipo: Talento → Evaluaciones |

## 8. Datos principales

Campañas (`performance_cycles`), formularios, escalas, evaluaciones (`reviews`), respuestas, asignaciones de evaluador, calibraciones, resultados, acuses.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección DES.

- **RN-DES-01**: Si una campaña se lanza → la población se congela con sus jefes vigentes a la fecha de corte; los cambios posteriores se gestionan caso a caso.
- **RN-DES-02** (parámetro): Si las respuestas de pares o de subordinados son menos que el mínimo de anonimato (parámetro) → no se muestran desglosadas; se agrupan con otra categoría o se omiten.
- **RN-DES-03**: Si una evaluación fue enviada → no se edita salvo reapertura por RRHH con motivo.
- **RN-DES-04**: Si se calcula el resultado → se aplican los pesos por sección y por tipo de evaluador definidos en la campaña.
- **RN-DES-05**: Si se calibra una calificación → se guarda la original, la ajustada, quién y por qué.
- **RN-DES-06**: Si los resultados no están publicados → el evaluado no los ve.
- **RN-DES-07**: Si el evaluado cesa durante la campaña → sus evaluaciones pendientes se cancelan y las que debía responder se reasignan o se excluyen.
- **RN-DES-08** (parámetro): Si un evaluador no completa antes de la fecha límite → se recuerda y, vencido el plazo de gracia (parámetro), RRHH puede cerrar sin esa respuesta.
- **RN-DES-09**: Si el resultado alimenta revisión salarial, 9-box o sucesión → es un insumo; ninguna de esas decisiones se toma de forma automática.
- **RN-DES-10**: Si un evaluador es jefe desde hace menos que el tiempo mínimo (parámetro) → se ofrece incluir al jefe anterior como evaluador adicional.

## 10. Estados

- ME-25 · Campaña de evaluación (`05-maquinas-de-estado.md`): estados Borrador, En evaluación, En calibración, Resultados publicados, Cerrada, Cancelada.
- ME-26 · Evaluación individual (`05-maquinas-de-estado.md`): estados Asignada, En progreso, Enviada, Calibrada, Publicada, Con acuse, Cancelada.

## 11. Eventos

**Emite:**

- `review_cycle.launched`, `review_cycle.closed`, `review.assigned`, `review.submitted`, `review.calibrated`, `review.published`, `review.acknowledged`.

**Consume:**

- `employment.manager_changed`, `employee.terminated`, `objective.closed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Persona con dos jefes en el periodo.
- Ingreso reciente por debajo de la antigüedad mínima de la campaña.
- Evaluación 360° con un solo subordinado.

## 14. Criterios de aceptación generales

- Se configuran campañas 90°, 180°, 270° y 360° con competencias, preguntas y escalas, y sus resultados aparecen en la ficha.
