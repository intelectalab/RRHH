# M-ONB · Pre-onboarding y onboarding

Fase 4 · Talento · Épica `ONB` · Reglas `RN-ONB-nn` · Módulo comercial: Onboarding y offboarding (`onboarding`)

## 1. Objetivo

Convertir al candidato aceptado en colaborador productivo con procesos diseñados por plantilla, tareas con responsable y seguimiento de avance.

## 2. Alcance

- Pre-onboarding: portal del futuro colaborador para datos, documentos, cuenta bancaria, contacto de emergencia, régimen pensionario y derechohabientes antes del ingreso.
- Diseñador de plantillas por empresa, sede, puesto y unidad, con hitos relativos al ingreso (7 días antes, día 1, semana 1, 30, 60 y 90 días).
- Tareas para RRHH, TI, jefe, colaborador y compañero guía; documentos, firmas, cursos, activos, accesos, reuniones, encuestas y evaluación de periodo de prueba.
- Porcentaje de avance; recordatorios; cierre.
- Obligaciones de ingreso en Perú: entrega de reglamentos, política salarial, capacitación en hostigamiento y SST, declaración de beneficiarios de Vida Ley, alta en T-Registro.

## 3. Fuera de alcance

- El alta laboral en sí (M-COL). Cursos (M-LMS). Activos (M-ACT).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §15 Pre-onboarding; §16 Onboarding; §77 Tareas RRHH.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH diseña y supervisa. Jefe, TI y otros ejecutan tareas. Futuro colaborador completa lo suyo.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `workflow.tasks.view` | Ver tareas internas | Administrador empresa, RRHH, Manager, SST; solo sobre lo propio o asignado: Payroll, Recruiter, Empleado |
| `workflow.tasks.cancel` | Cancelar tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `workflow.tasks.complete` | Completar tareas internas | Administrador empresa, RRHH, Manager, SST; solo sobre lo propio o asignado: Payroll, Recruiter, Empleado |
| `workflow.tasks.create` | Crear tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `workflow.tasks.update` | Editar tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `onboarding.processes.view` | Ver procesos de onboarding | Administrador empresa, RRHH, Recruiter, Manager, Auditor |
| `onboarding.processes.cancel` | Cancelar procesos de onboarding | Administrador empresa, RRHH |
| `onboarding.processes.complete` | Completar procesos de onboarding | Administrador empresa, RRHH |
| `onboarding.processes.create` | Crear procesos de onboarding | Administrador empresa, RRHH |
| `onboarding.processes.start` | Iniciar procesos de onboarding | Administrador empresa, RRHH |
| `onboarding.processes.complete_task` **(+)** | Completar las tareas de onboarding que le asignan | solo sobre lo propio o asignado: Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, SST |
| `onboarding.processes.review_preboarding` **(+)** | Revisar y aprobar datos y documentos de pre-ingreso | Administrador empresa, RRHH |
| `onboarding.templates.view` | Ver plantillas de onboarding y offboarding | Administrador empresa, RRHH, Auditor |
| `onboarding.templates.create` | Crear plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.templates.publish` | Publicar plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.templates.retire` | Retirar plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.templates.update` | Editar plantillas de onboarding y offboarding | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Pre-onboarding.** 1) Al aceptarse la oferta, el futuro colaborador recibe acceso limitado. 2) Completa datos y sube documentos. 3) RRHH revisa y aprueba o pide corrección. 4) Con todo aprobado, se confirma el ingreso y los datos pasan a la ficha.

**Onboarding.** 1) `employee.hired` selecciona la plantilla más específica. 2) Se crean tareas con fecha calculada desde la fecha de ingreso y responsable resuelto. 3) Cada responsable las completa desde sus pendientes. 4) El avance se actualiza. 5) A los 30, 60 y 90 días se lanzan seguimiento, encuesta y evaluación de periodo de prueba. 6) Cierre con todas las tareas obligatorias completas.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Onboarding | Procesos en curso con avance, hito actual, tareas vencidas; iniciar | Prototipo: Talento → Onboarding |
| Detalle del proceso | Línea de hitos, tareas por responsable y estado, documentos, firmas | Prototipo: Talento → Onboarding |
| Plantillas de onboarding | Lista por criterio; editor de hitos y tareas | Prototipo: Talento → Onboarding |
| Pre-ingreso | Formulario y carga de documentos del futuro colaborador; avance | Prototipo: Talento → Onboarding |
| Mi onboarding | Tareas y agenda del nuevo colaborador | Prototipo: Talento → Onboarding |

## 8. Datos principales

Plantillas y tareas de plantilla, procesos (`onboarding_processes`), tareas del proceso, expedientes de pre-ingreso.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ONB.

- **RN-ONB-01**: Si se registra un ingreso → se inicia el proceso con la plantilla que coincide con más criterios (puesto, unidad, sede, empresa); si ninguna aplica, la plantilla por defecto.
- **RN-ONB-02**: Si una tarea tiene fecha relativa → se calcula desde la fecha de ingreso y se recalcula si esa fecha cambia.
- **RN-ONB-03**: Si el responsable de una tarea es un rol o una relación (jefe, TI de la sede) → se resuelve a una persona al crear la tarea y se reasigna si esa persona cambia.
- **RN-ONB-04**: Si quedan tareas obligatorias sin completar → el proceso no puede cerrarse como «Completado».
- **RN-ONB-05**: Si se calcula el avance → es tareas completadas sobre tareas totales, ponderadas si la plantilla define pesos.
- **RN-ONB-06**: Si el futuro colaborador no ha ingresado → su acceso se limita al pre-ingreso; no ve directorio ni datos internos.
- **RN-ONB-07**: Si el ingreso se cancela antes de la fecha → el proceso se cancela, las tareas pendientes se anulan y los datos de pre-ingreso se tratan como de candidato no contratado.
- **RN-ONB-08** (parámetro): Si una tarea supera su fecha → se recuerda al responsable y, tras el plazo de escalamiento (parámetro), a su jefe y a RRHH.
- **RN-ONB-09**: Si la empresa opera en Perú → la plantilla por defecto incluye las tareas de cumplimiento de ingreso (reglamentos, política salarial, capacitaciones obligatorias, Vida Ley, T-Registro) y no se pueden quitar sin dejar constancia.
- **RN-ONB-10** (parámetro): Si se acerca el fin del periodo de prueba (parámetro de anticipación) → se crea la evaluación de periodo de prueba para el jefe.

## 10. Estados

- ME-08 · Proceso de onboarding (`05-maquinas-de-estado.md`): estados Pre-ingreso, En curso, Pausado, Completado, Cancelado.

## 11. Eventos

**Emite:**

- `preboarding.started`, `preboarding.completed`, `onboarding.started`, `onboarding.task_completed`, `onboarding.completed`, `onboarding.cancelled`.

**Consume:**

- `offer.accepted`, `employee.hired`, `employee.terminated`, `signature.completed`, `course.completed`, `asset.assigned`.

## 12. Integraciones

Creación de cuentas y accesos en sistemas de TI por adaptador (M-INT).

## 13. Casos borde

- Ingreso sin proceso de selección previo (alta directa).
- Reingreso: plantilla abreviada.
- Cambio de fecha de ingreso con tareas ya completadas.
- Cese dentro del periodo de onboarding.

## 14. Criterios de aceptación generales

- Un proceso muestra hitos (7 días antes, día 1, semana 1, 30, 60, 90 días) con tareas, responsables y porcentaje de avance.
