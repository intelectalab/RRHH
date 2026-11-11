# M-LMS · Aprendizaje y plan anual de capacitación

Fase 5 · Desarrollo y compensación · Épica `LMS` · Reglas `RN-LMS-nn` · Módulo comercial: Aprendizaje (`learning`)

## 1. Objetivo

Capacitar al personal con cursos internos y externos, asignaciones obligatorias con vencimiento y un plan anual medible.

## 2. Alcance

- Cursos con categorías, lecciones, videos, PDF, paquetes SCORM, evaluaciones y certificados.
- Sesiones presenciales o en vivo con asistencia.
- Asignación individual, por equipo, puesto, empresa o regla; obligatoriedad, fecha límite, vigencia y recertificación.
- Seguimiento: no iniciado, en progreso, completado, vencido.
- Plan anual: cursos, presupuesto, participantes, horas, proveedores, resultados; planificado frente a ejecutado.
- Capacitaciones legales (SST, hostigamiento) con evidencia para registros obligatorios.
- Rutas de aprendizaje; solicitudes de capacitación con aprobación.

## 3. Fuera de alcance

- Creación de contenidos audiovisuales. Catálogos externos de pago (integración).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §47 LMS / capacitación; §48 Plan anual de capacitación.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-15 Hostigamiento sexual (Ley 27942).

## 5. Actores y permisos

- RRHH o responsable de formación administra. Instructor dicta y califica. Manager asigna y sigue. Colaborador cursa.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `learning.courses.view` | Ver cursos | Administrador empresa, RRHH, Manager, Empleado, Auditor, SST |
| `learning.courses.archive` | Archivar cursos | Administrador empresa, RRHH, SST |
| `learning.courses.create` | Crear cursos | Administrador empresa, RRHH, SST |
| `learning.courses.publish` | Publicar cursos | Administrador empresa, RRHH, SST |
| `learning.courses.update` | Editar cursos | Administrador empresa, RRHH, SST |
| `learning.enrollments.view` | Ver inscripciones | Administrador empresa, RRHH, Manager, Auditor, SST; solo sobre lo propio o asignado: Empleado |
| `learning.enrollments.complete` | Completar inscripciones | solo sobre lo propio o asignado: todos los roles de organización |
| `learning.enrollments.create` | Crear inscripciones | Administrador empresa, RRHH, Manager, SST |
| `learning.enrollments.reset` | Reiniciar inscripciones | Administrador empresa, RRHH, SST |
| `learning.enrollments.update` | Editar inscripciones | Administrador empresa, RRHH, SST |
| `learning.training_plans.view` | Ver el plan anual de capacitación | Administrador empresa, RRHH, Auditor |
| `learning.training_plans.approve` | Aprobar el plan anual de capacitación | Administrador empresa |
| `learning.training_plans.close` | Cerrar el plan anual de capacitación | Administrador empresa, RRHH |
| `learning.training_plans.create` | Crear el plan anual de capacitación | Administrador empresa, RRHH |
| `learning.training_plans.update` | Editar el plan anual de capacitación | Administrador empresa, RRHH |
| `learning.certificates.issue` **(+)** | Emitir y revocar certificados | Administrador empresa, RRHH, SST |
| `learning.training_requests.approve` **(+)** | Aprobar solicitudes de capacitación | Administrador empresa, RRHH, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Asignación.** 1) Se elige curso y destinatarios o regla. 2) Se crean inscripciones con fecha límite. 3) El colaborador cursa y rinde la evaluación. 4) Aprobado: «Completado» y certificado. 5) Pasada la fecha límite sin completar: «Vencido» y alerta.

**Plan anual.** 1) Se registran necesidades (brechas, obligaciones legales, pedidos). 2) Se planifican cursos, participantes, horas y presupuesto. 3) Se ejecutan y registran. 4) El tablero compara planificado y ejecutado.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Cursos | Catálogo con categoría, duración, tipo, inscritos; crear | Prototipo: Aprendizaje → Cursos |
| Editor de curso | Lecciones, materiales, evaluación, certificado, vigencia | Prototipo: Aprendizaje → Cursos |
| Inscripciones | Avance por persona y curso; asignar, recordar | Prototipo: Aprendizaje → Cursos |
| Plan anual | Planificado frente a ejecutado en cursos, horas, participantes y presupuesto | Prototipo: Aprendizaje → Plan anual |
| Certificados | Emitidos, vigencia, descarga, verificación | Prototipo: Aprendizaje → Cursos |
| Mis cursos | Asignados y disponibles, continuar, certificados | Prototipo: Aprendizaje → Cursos |
| Capacitaciones del colaborador | Historial en la ficha | Prototipo: Personas → Colaboradores → Ficha → pestaña Capacitaciones |

## 8. Datos principales

Cursos (`courses`), lecciones, materiales, evaluaciones e intentos, sesiones y asistencia, inscripciones (`enrollments`), certificados, plan anual y partidas, proveedores, solicitudes.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección LMS.

- **RN-LMS-01**: Si un curso tiene evaluación → se completa solo al alcanzar la nota mínima; los intentos se limitan al máximo configurado.
- **RN-LMS-02** (parámetro): Si una inscripción obligatoria supera su fecha límite sin completarse → pasa a «Vencido» y se alerta al colaborador, a su jefe y al responsable.
- **RN-LMS-03** (parámetro): Si el curso tiene vigencia (recertificación) → al acercarse el vencimiento del certificado se crea una nueva inscripción automática (anticipación: parámetro).
- **RN-LMS-04**: Si un curso se asigna por regla (puesto, unidad) → quien entra a la población después recibe la inscripción automáticamente.
- **RN-LMS-05**: Si el contenido de un curso cambia de versión → las inscripciones completadas conservan la versión cursada.
- **RN-LMS-06**: Si un curso es de tipo legal (SST, hostigamiento sexual) → su asistencia y evaluación alimentan el registro obligatorio correspondiente.
- **RN-LMS-07**: Si la persona cesa → sus inscripciones pendientes se cancelan y sus certificados se conservan.
- **RN-LMS-08** (parámetro): Si una solicitud de capacitación supera el presupuesto disponible del plan → exige aprobación adicional (parámetro).
- **RN-LMS-09**: Si se emite un certificado → lleva código de verificación consultable.

## 10. Estados

- ME-28 · Inscripción a curso (`05-maquinas-de-estado.md`): estados No iniciado, En progreso, Completado, Vencido, Cancelado.

## 11. Eventos

**Emite:**

- `course.published`, `course.assigned`, `enrollment.started`, `course.completed`, `course.expired`, `certificate.issued`, `certificate.expiring`, `training_plan.updated`.

**Consume:**

- `employee.hired`, `employment.assignment_changed`, `employee.terminated`, `development_plan.action_added`, `onboarding.started`.

## 12. Integraciones

Paquetes SCORM y catálogos externos, videoconferencia (M-INT).

## 13. Casos borde

- Sesión presencial con asistencia parcial.
- Curso obligatorio para alguien con licencia larga: el plazo se suspende.
- Recertificación de un curso retirado del catálogo.

## 14. Criterios de aceptación generales

- Los cursos se asignan a persona, equipo, puesto o empresa, muestran los cuatro estados de seguimiento y el plan anual compara planificado y ejecutado.
