# M-OFF · Offboarding

Fase 4 · Talento · Épica `OFF` · Reglas `RN-OFF-nn` · Módulo comercial: Onboarding y offboarding (`onboarding`)

## 1. Objetivo

Conducir la salida de forma ordenada y completa: nada se olvida, los accesos se cierran, los activos vuelven y la liquidación sale a tiempo.

## 2. Alcance

- Tipos de salida: renuncia, despido, fin de contrato, mutuo acuerdo, jubilación, fallecimiento, otros; motivo y submotivo.
- Lista de comprobación por plantilla: carta, liquidación, devolución de activos y EPP, desactivación de accesos, entrega de documentos, encuesta y entrevista de salida, traspaso de funciones.
- Reasignación de aprobaciones, equipo y pendientes del saliente.
- Documentos de cese y bajas legales (con M-LIQ y M-DEC).
- Marca de recontratable y notas confidenciales.

## 3. Fuera de alcance

- Cálculo de liquidación (M-LIQ). Procedimiento disciplinario previo a un despido (M-DIS).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §17 Offboarding; §77 Tareas RRHH.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH conduce. Jefe, TI, Payroll y administración ejecutan tareas. Colaborador entrega y responde.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `workflow.tasks.view` | Ver tareas internas | Administrador empresa, RRHH, Manager, SST; solo sobre lo propio o asignado: Payroll, Recruiter, Empleado |
| `workflow.tasks.cancel` | Cancelar tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `workflow.tasks.complete` | Completar tareas internas | Administrador empresa, RRHH, Manager, SST; solo sobre lo propio o asignado: Payroll, Recruiter, Empleado |
| `workflow.tasks.create` | Crear tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `workflow.tasks.update` | Editar tareas internas | Administrador empresa, RRHH, Recruiter, Manager, SST |
| `onboarding.templates.view` | Ver plantillas de onboarding y offboarding | Administrador empresa, RRHH, Auditor |
| `onboarding.templates.create` | Crear plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.templates.publish` | Publicar plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.templates.retire` | Retirar plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.templates.update` | Editar plantillas de onboarding y offboarding | Administrador empresa, RRHH |
| `onboarding.offboardings.view` | Ver procesos de salida | Administrador empresa, RRHH, Payroll, Manager, Auditor |
| `onboarding.offboardings.cancel` | Cancelar procesos de salida | Administrador empresa, RRHH |
| `onboarding.offboardings.complete` | Completar procesos de salida | Administrador empresa, RRHH |
| `onboarding.offboardings.create` | Crear procesos de salida | Administrador empresa, RRHH |
| `onboarding.offboardings.start` | Iniciar procesos de salida | Administrador empresa, RRHH |
| `onboarding.offboardings.update` | Editar procesos de salida | Administrador empresa, RRHH |
| `onboarding.offboardings.complete_task` **(+)** | Completar las tareas de salida que le asignan | solo sobre lo propio o asignado: Administrador empresa, RRHH, Payroll, Manager, Empleado, SST |
| `onboarding.offboardings.exit_reason.view` **(S)** **(+)** | Ver motivo detallado, entrevista de salida y marca de recontratable | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Salida.** 1) RRHH registra el cese (o la renuncia recibida) con fecha y motivo. 2) Se inicia el proceso con la plantilla del tipo de salida. 3) Se crean tareas: carta, liquidación, activos, accesos, documentos, encuesta, entrevista. 4) Se reasignan aprobaciones y equipo. 5) El último día se desactivan accesos. 6) Con tareas y liquidación completas, se cierra el proceso.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Offboarding | Procesos en curso con fecha de cese, avance y pendientes críticos | Prototipo: Talento → Offboarding |
| Detalle de salida | Lista de comprobación, activos por devolver, accesos, liquidación, documentos, entrevista | Prototipo: Talento → Offboarding |
| Entrevista de salida | Formulario confidencial | Prototipo: Talento → Offboarding |

## 8. Datos principales

Procesos de salida (`terminations`), tareas, motivos y submotivos, entrevistas de salida, marca de recontratable.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección OFF.

- **RN-OFF-01**: Si se registra un cese → se inicia el proceso de salida con la plantilla del tipo de cese.
- **RN-OFF-02**: Si llega la fecha efectiva de cese → se desactivan accesos internos y se retiran roles; queda el modo ex-colaborador.
- **RN-OFF-03**: Si el saliente tiene aprobaciones pendientes o es jefe → se exige designar a quién pasan antes de cerrar el proceso.
- **RN-OFF-04**: Si hay activos o EPP sin devolver → la tarea queda abierta y se informa a liquidación para el descuento según política y norma.
- **RN-OFF-05**: Si el motivo detallado o la entrevista de salida se consultan → requieren el permiso sensible; el jefe solo ve el tipo de salida.
- **RN-OFF-06**: Si el proceso no tiene liquidación pagada ni documentos entregados → no puede cerrarse como «Completado».
- **RN-OFF-07**: Si la salida es por despido → debe existir un caso disciplinario cerrado con esa medida o una justificación registrada por RRHH.
- **RN-OFF-08**: Si el cese se anula antes de la fecha efectiva → el proceso se cancela y se revierten las tareas no ejecutadas.
- **RN-OFF-09** (parámetro): Si se configura encuesta de salida → se envía de forma automática con la anticipación de la plantilla (parámetro).

## 10. Estados

- ME-09 · Proceso de offboarding (`05-maquinas-de-estado.md`): estados Iniciado, En curso, Pendiente de liquidación, Completado, Cancelado.

## 11. Eventos

**Emite:**

- `offboarding.started`, `offboarding.task_completed`, `offboarding.access_revoked`, `offboarding.completed`, `offboarding.cancelled`.

**Consume:**

- `employee.terminated`, `contract.expired`, `settlement.paid`, `asset.returned`, `disciplinary.closed`.

## 12. Integraciones

Desactivación de cuentas en sistemas de TI (M-INT).

## 13. Casos borde

- Abandono de trabajo: fecha de cese retroactiva.
- Fallecimiento: tareas hacia beneficiarios.
- Renuncia con exoneración del plazo de preaviso.
- Salida de una persona con dos relaciones (solo una cesa).

## 14. Criterios de aceptación generales

- Cada salida registra motivo y recorre carta, liquidación, activos, accesos, documentos, encuesta y entrevista.
