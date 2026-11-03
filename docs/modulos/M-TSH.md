# M-TSH · Hojas de tiempo por proyecto

Fase 2 · Tiempo · Épica `TSH` · Reglas `RN-TSH-nn` · Módulo comercial: Hojas de tiempo (`timesheets`)

## 1. Objetivo

Saber a qué proyectos, clientes o actividades se dedica el tiempo y cuánto cuesta, sin duplicar la asistencia.

## 2. Alcance

- Proyectos, clientes, tareas o actividades y centros de costo; asignación de personas con vigencia y dedicación.
- Registro diario o semanal de horas; temporizador; propuesta automática desde la asistencia.
- Envío y aprobación semanal; bloqueo de periodos.
- Costeo por proyecto con costo hora derivado de la planilla (solo quien tenga permiso); distribución de costo a centros de costo para el asiento.

## 3. Fuera de alcance

- Facturación a clientes. Gestión de proyectos (tareas, cronogramas).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-13 Hojas de tiempo por proyecto.

## 5. Actores y permisos

- Empleado registra. Responsable de proyecto o manager aprueba. Finanzas y RRHH analizan costos.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `time.timesheets.view` | Ver hojas de tiempo | Administrador empresa, RRHH, Manager, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `time.timesheets.approve` | Aprobar hojas de tiempo | Administrador empresa, RRHH, Manager |
| `time.timesheets.create` | Crear hojas de tiempo | solo sobre lo propio o asignado: Manager, Empleado |
| `time.timesheets.reject` | Rechazar hojas de tiempo | Administrador empresa, RRHH, Manager |
| `time.timesheets.submit` | Enviar hojas de tiempo | solo sobre lo propio o asignado: Manager, Empleado |
| `time.timesheets.update` | Editar hojas de tiempo | solo sobre lo propio o asignado: Manager, Empleado |
| `time.projects.view` | Ver proyectos, actividades y asignaciones | Administrador empresa, RRHH, Manager, Empleado, Auditor, Contador |
| `time.projects.close` | Cerrar proyectos, actividades y asignaciones | Administrador empresa, RRHH, Manager |
| `time.projects.create` | Crear proyectos, actividades y asignaciones | Administrador empresa, RRHH, Manager |
| `time.projects.update` | Editar proyectos, actividades y asignaciones | Administrador empresa, RRHH, Manager |
| `time.timesheets.cost.view` **(S)** **(+)** | Ver costo por hora y costo por proyecto | Administrador empresa, Payroll, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Semana.** 1) El colaborador abre su semana; el sistema propone horas según asistencia y asignaciones. 2) Distribuye por proyecto y actividad. 3) Envía. 4) El aprobador revisa y aprueba o devuelve. 5) Aprobada, alimenta costeo y distribución contable.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Mi hoja de tiempo | Semana con proyectos en filas y días en columnas, total frente a asistencia, enviar | Prototipo: Tiempo → Hojas de tiempo |
| Hojas por aprobar | Semanas enviadas con desviaciones; aprobar o devolver | Prototipo: Tiempo → Hojas de tiempo |
| Proyectos | Catálogo con responsable, centro de costo, asignados, horas y costo (con permiso) | Prototipo: Tiempo → Hojas de tiempo |
| Costeo | Horas y costo por proyecto, persona y periodo | Prototipo: Tiempo → Hojas de tiempo |

## 8. Datos principales

Proyectos, actividades, asignaciones a proyecto, hojas de tiempo (`timesheets`) y líneas, costo hora por periodo.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección TSH.

- **RN-TSH-01** (parámetro): Si las horas registradas del día difieren de las de asistencia más allá de la tolerancia (parámetro) → se advierte y la hoja no puede enviarse sin justificación.
- **RN-TSH-02**: Si la persona no está asignada al proyecto en esa fecha → no puede imputarle horas.
- **RN-TSH-03**: Si una hoja está aprobada → no se edita; una corrección exige devolución por el aprobador.
- **RN-TSH-04**: Si se calcula el costo hora → se deriva del costo laboral del periodo cerrado de planilla y solo lo ven quienes tienen el permiso de costo.
- **RN-TSH-05**: Si el periodo de hojas está bloqueado → no admite registros nuevos.
- **RN-TSH-06** (parámetro): Si una hoja no se envía antes del límite semanal (parámetro) → se recuerda al colaborador y a su jefe.
- **RN-TSH-07**: Si existen hojas aprobadas en el periodo → la distribución contable del costo de la persona usa esos porcentajes en lugar de su centro de costo fijo.

## 10. Estados

- ME-18 · Hoja de tiempo (`05-maquinas-de-estado.md`): estados Abierta, Enviada, Aprobada, Devuelta, Bloqueada.

## 11. Eventos

**Emite:**

- `timesheet.submitted`, `timesheet.approved`, `timesheet.returned`, `project.created`.

**Consume:**

- `attendance.day_calculated`, `payroll.closed`.

## 12. Integraciones

Herramientas externas de proyectos por API (M-INT).

## 13. Casos borde

- Vacaciones y ausencias aparecen como líneas no imputables.
- Persona asignada a varios proyectos con dedicación parcial.
- Reapertura de una semana aprobada tras cerrar planilla.

## 14. Criterios de aceptación generales

- El costo por proyecto se obtiene sin que los aprobadores vean sueldos individuales.
