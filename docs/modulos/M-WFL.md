# M-WFL · Motor de aprobaciones y workflows

Fase 0 · Cimientos · Épica `WFL` · Reglas `RN-WFL-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Ofrecer un único motor para aprobar trámites y automatizar reacciones a eventos, reutilizado por todos los módulos, con diseñador sin código, bandeja única y centro de automatizaciones.

## 2. Alcance

- Aprobaciones de 1 a N niveles, secuenciales o paralelas, con aprobadores por rol, jefe directo, jefe de unidad, responsable de centro de costo, persona fija o regla.
- Condiciones por datos del trámite (monto, días, empresa, sede, unidad, tipo).
- Bandeja «Pendientes» y centro de aprobaciones con acciones masivas seguras.
- Diseñador CUANDO → SI → ENTONCES y constructor visual con nodos Inicio, Condición, Aprobación, Acción, Notificación, Espera, Fin.
- Acciones: crear tarea, asignar proceso de onboarding, asignar curso, pedir documento, notificar, cambiar campo permitido, llamar webhook.
- Tareas internas de RRHH (responsable, fecha, estado, prioridad, vínculo con proceso).
- Centro de automatizaciones: reglas activas, ejecuciones, errores, última y próxima ejecución, registro detallado.
- Escalamiento, recordatorios, delegación y reemplazo por ausencia.

## 3. Fuera de alcance

- Las reglas de negocio de cada trámite (qué valida una solicitud de vacaciones) viven en su módulo; el motor solo decide quién aprueba y en qué orden.
- Decisiones de alto impacto automáticas: prohibidas.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §55 Workflows; §56 Aprobaciones; §76 Centro de automatizaciones; §77 Tareas RRHH; §87 Bandeja «Pendientes»; §95 Workflow builder; §103 Event-driven; §109 Centro de aprobaciones.

**Correcciones que incorpora:** C-04 La plataforma SaaS va en los cimientos.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Administrador y RRHH diseñan. Aprobadores (Manager, RRHH, Payroll, finanzas) resuelven. Solicitantes consultan el avance.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `workflow.approval_flows.view` | Ver flujos de aprobación | Administrador empresa, RRHH, Auditor |
| `workflow.approval_flows.create` | Crear flujos de aprobación | Administrador empresa |
| `workflow.approval_flows.publish` | Publicar flujos de aprobación | Administrador empresa |
| `workflow.approval_flows.retire` | Retirar flujos de aprobación | Administrador empresa |
| `workflow.approval_flows.update` | Editar flujos de aprobación | Administrador empresa |
| `workflow.approval_requests.view` | Ver solicitudes de aprobación | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Payroll, Recruiter, Manager, Empleado, SST, Contador |
| `workflow.approval_requests.approve` | Aprobar solicitudes de aprobación | solo sobre lo propio o asignado: Administrador empresa, RRHH, Payroll, Recruiter, Manager, SST, Contador |
| `workflow.approval_requests.delegate` | Delegar solicitudes de aprobación | solo sobre lo propio o asignado: Administrador empresa, RRHH, Payroll, Manager |
| `workflow.approval_requests.reject` | Rechazar solicitudes de aprobación | solo sobre lo propio o asignado: Administrador empresa, RRHH, Payroll, Recruiter, Manager, SST, Contador |
| `workflow.approval_requests.reassign` **(+)** | Reasignar aprobaciones atascadas | Administrador empresa, RRHH |
| `workflow.workflows.view` | Ver automatizaciones y reglas | Administrador empresa, RRHH, Auditor |
| `workflow.workflows.activate` | Activar automatizaciones y reglas | Administrador empresa |
| `workflow.workflows.create` | Crear automatizaciones y reglas | Administrador empresa |
| `workflow.workflows.pause` | Pausar automatizaciones y reglas | Administrador empresa |
| `workflow.workflows.retire` | Retirar automatizaciones y reglas | Administrador empresa |
| `workflow.workflows.update` | Editar automatizaciones y reglas | Administrador empresa |
| `workflow.runs.view` | Ver ejecuciones del centro de automatizaciones | Administrador empresa, RRHH, Auditor |
| `workflow.runs.cancel` | Cancelar ejecuciones del centro de automatizaciones | Administrador empresa |
| `workflow.runs.retry` | Reintentar ejecuciones del centro de automatizaciones | Administrador empresa |
| `workflow.tasks.view` | Ver tareas internas | Administrador empresa, RRHH, Manager, SST; solo sobre lo propio o asignado: Payroll, Recruiter, Empleado |
| `workflow.tasks.cancel` | Cancelar tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `workflow.tasks.complete` | Completar tareas internas | Administrador empresa, RRHH, Manager, SST; solo sobre lo propio o asignado: Payroll, Recruiter, Empleado |
| `workflow.tasks.create` | Crear tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `workflow.tasks.update` | Editar tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Aprobación de un trámite.** 1) Un módulo envía una solicitud al motor con tipo y datos. 2) El motor elige el flujo publicado que aplica (por empresa, tipo y condiciones). 3) Crea los pasos y asigna aprobadores resolviendo reglas a personas concretas. 4) Cada aprobador decide desde su bandeja (aprobar, rechazar con motivo, pedir corrección). 5) Al completarse, el motor devuelve el resultado al módulo, que ejecuta su transición.

**Automatización.** 1) Se publica una regla: CUANDO `employee.hired` SI unidad = Tecnología ENTONCES asignar onboarding TI, crear tareas, solicitar laptop, notificar al jefe. 2) Al llegar el evento, el motor evalúa condiciones. 3) Ejecuta acciones en orden, cada una idempotente. 4) Registra la ejecución con entradas, salidas y errores.

**Escalamiento.** 1) Un paso supera su plazo. 2) Se envía recordatorio. 3) Si persiste, se escala al siguiente nivel o al suplente configurado.

**Versionado.** 1) Se edita un borrador. 2) Se prueba con datos de ejemplo. 3) Se publica como nueva versión; los trámites en curso terminan con la versión con la que empezaron.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Pendientes | Lista única de lo que espera al usuario (aprobar, firmar, evaluar, completar), agrupada por tipo, con contador | Prototipo: Pendientes |
| Centro de aprobaciones | Aprobaciones por tipo con detalle, aprobar o rechazar, masivo cuando es seguro, historial | Prototipo: Automatización → Aprobaciones |
| Flujos de aprobación | Lista de flujos por tipo de trámite, versión, estado; editor de niveles y condiciones | Prototipo: Automatización → Workflows |
| Constructor visual | Lienzo con nodos y conexiones, panel de propiedades, prueba con datos de ejemplo | Prototipo: Automatización → Workflows → Editor |
| Reglas (CUANDO–SI–ENTONCES) | Reglas simples por evento con condiciones y acciones | Prototipo: Automatización → Automatizaciones → pestaña Reglas |
| Centro de automatizaciones | Reglas activas, ejecuciones, errores, última y próxima ejecución, registro por ejecución, reintentar | Prototipo: Automatización → Automatizaciones → pestaña Ejecuciones |
| Tareas | Tablero y lista de tareas internas por responsable, fecha, prioridad y proceso de origen | Prototipo: Tareas |

## 8. Datos principales

Definiciones de flujo y versiones, nodos y conexiones, solicitudes de aprobación, pasos y decisiones, ejecuciones y su registro, tareas, reglas de escalamiento, suplencias.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección WFL.

- **RN-WFL-01**: Si un trámite requiere aprobación → el motor selecciona el flujo publicado más específico (empresa + tipo + condición) y, si no hay ninguno, usa el flujo por defecto del tipo.
- **RN-WFL-02**: Si el paso es secuencial → el siguiente nivel no recibe la solicitud hasta que el anterior aprueba.
- **RN-WFL-03** (parámetro): Si el paso es paralelo → se cumple cuando aprueba el mínimo requerido (todos, uno o N; parámetro del paso).
- **RN-WFL-04**: Si cualquier aprobador rechaza → el trámite termina rechazado y el motivo es obligatorio.
- **RN-WFL-05**: Si el aprobador resuelto es el propio solicitante → el paso se salta al siguiente nivel; si no hay siguiente, va a RRHH.
- **RN-WFL-06** (parámetro): Si un paso supera su plazo (parámetro por flujo) → se envía recordatorio y, al segundo vencimiento, se escala según la regla configurada.
- **RN-WFL-07**: Si el aprobador está ausente (vacaciones aprobadas) o sin usuario activo → la solicitud va a su suplente o delegado; si no hay, a su jefe.
- **RN-WFL-08**: Si se publica una nueva versión de un flujo → los trámites en curso continúan con la versión anterior.
- **RN-WFL-09**: Si una automatización intenta contratar, cesar, sancionar, promover o cambiar un sueldo sin paso de aprobación humana → no se permite publicarla.
- **RN-WFL-10**: Si una acción de automatización falla → se registra el error, se reintenta según política y no se ejecutan las acciones siguientes que dependan de ella.
- **RN-WFL-11** (parámetro): Si una regla se dispara en bucle (más ejecuciones que el máximo por registro y ventana; parámetro) → se pausa y se alerta al administrador.
- **RN-WFL-12**: Si se aprueba en masa → solo se permite para tipos marcados como seguros y cada aprobación se audita individualmente.
- **RN-WFL-13**: Si un trámite se cancela por el solicitante antes de la decisión final → todos los pasos pendientes se anulan y se notifica a los aprobadores.

## 10. Estados

- ME-42 · Definición de workflow (`05-maquinas-de-estado.md`): estados Borrador, Publicado, Pausado, Archivado.
- ME-43 · Solicitud de aprobación (`05-maquinas-de-estado.md`): estados Pendiente, En curso, Aprobada, Rechazada, Cancelada, Escalada.
- ME-44 · Ejecución de automatización (`05-maquinas-de-estado.md`): estados En cola, En ejecución, En espera, Completada, Fallida, Cancelada.

## 11. Eventos

**Emite:**

- `approval.requested`, `approval.step_approved`, `approval.approved`, `approval.rejected`, `approval.escalated`, `approval.cancelled`, `workflow.published`, `workflow.run_failed`, `task.created`, `task.completed`.

**Consume:**

- Cualquier evento de dominio como disparador; `employment.manager_changed` (reasigna aprobaciones pendientes al nuevo jefe).

## 12. Integraciones

Webhooks salientes como acción (M-API); calendario para tareas (M-INT).

## 13. Casos borde

- Cambio de jefe con aprobaciones pendientes: pasan al nuevo jefe y se notifica.
- Flujo sin aprobador resoluble (posición de jefe vacante): cae al jefe del nivel superior y, en último caso, a RRHH.
- Aprobación paralela donde un aprobador deja la empresa: se recalcula el mínimo.
- Automatización que reacciona a su propio efecto: protección de bucle.

## 14. Criterios de aceptación generales

- Vacaciones, gastos, horas extra, documentos, contrataciones, incrementos, headcount, cursos y solicitudes usan el mismo motor y la misma bandeja.
- Un flujo de N niveles con un paso paralelo se configura sin código y se prueba antes de publicar.
- El centro de automatizaciones muestra por regla: estado, ejecuciones, errores, última y próxima ejecución.
