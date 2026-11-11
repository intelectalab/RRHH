# M-TUR · Horarios, jornadas y turnos

Fase 2 · Tiempo · Épica `TUR` · Reglas `RN-TUR-nn` · Módulo comercial: Asistencia y turnos (`attendance`)

## 1. Objetivo

Definir cuándo debe trabajar cada persona: jornadas, horarios, descansos, tolerancias, feriados y turnos, con un planificador visual.

## 2. Alcance

- Jornada semanal y diaria, descansos, tolerancias, reglas de horas extra, horario nocturno.
- Horarios fijos, flexibles y por turnos (mañana, tarde, noche, partido, rotativo); patrones de rotación.
- Políticas por empresa, sede, unidad o grupo con herencia.
- Planificador en calendario con arrastrar y soltar, asignación masiva, copia de semanas, publicación y aviso al equipo.
- Intercambio de turnos entre compañeros con aprobación; cobertura mínima.

## 3. Fuera de alcance

- Marcación (M-ASI). Cálculo de sobretiempo (M-BHO).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §23 Turnos; §24 Horarios y jornadas.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH define políticas y horarios. Manager o supervisor planifica turnos. Empleado consulta y propone intercambios.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `time.holiday_calendars.view` | Ver calendarios y feriados | Administrador empresa, RRHH, Payroll, Manager, Empleado, Auditor |
| `time.holiday_calendars.create` | Crear calendarios y feriados | Administrador empresa, RRHH |
| `time.holiday_calendars.update` | Editar calendarios y feriados | Administrador empresa, RRHH |
| `time.schedules.view` | Ver jornadas, horarios y políticas de tiempo | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.schedules.archive` | Archivar jornadas, horarios y políticas de tiempo | Administrador empresa, RRHH |
| `time.schedules.create` | Crear jornadas, horarios y políticas de tiempo | Administrador empresa, RRHH |
| `time.schedules.update` | Editar jornadas, horarios y políticas de tiempo | Administrador empresa, RRHH |
| `time.shifts.view` | Ver turnos | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.shifts.archive` | Archivar turnos | Administrador empresa, RRHH |
| `time.shifts.create` | Crear turnos | Administrador empresa, RRHH |
| `time.shifts.update` | Editar turnos | Administrador empresa, RRHH |
| `time.shift_assignments.view` | Ver la planificación de turnos | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.shift_assignments.create` | Crear la planificación de turnos | Administrador empresa, RRHH, Manager |
| `time.shift_assignments.delete` | Eliminar la planificación de turnos | Administrador empresa, RRHH, Manager |
| `time.shift_assignments.publish` | Publicar la planificación de turnos | Administrador empresa, RRHH, Manager |
| `time.shift_assignments.update` | Editar la planificación de turnos | Administrador empresa, RRHH, Manager |
| `time.shift_assignments.swap` **(+)** | Proponer intercambio de turno | solo sobre lo propio o asignado: Empleado, Manager |
| `time.shift_assignments.approve_swap` **(+)** | Aprobar intercambios | Administrador empresa, RRHH, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Planificación.** 1) El planificador abre la semana o el mes de su unidad. 2) Arrastra turnos a personas o aplica un patrón. 3) El sistema valida descansos, jornada máxima, ausencias aprobadas y cobertura. 4) Publica; el equipo recibe su horario.

**Intercambio.** 1) Un colaborador propone cambiar su turno con un compañero. 2) El compañero acepta. 3) El manager aprueba tras las mismas validaciones. 4) Se actualiza el plan.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Horarios | Catálogo de jornadas y horarios con vigencia; asignación por persona o grupo | Prototipo: Tiempo → Turnos y horarios |
| Turnos | Calendario por unidad con personas en filas y días en columnas; arrastrar, copiar, asignar en bloque, publicar | Prototipo: Tiempo → Turnos y horarios |
| Mi horario | Horario y turnos publicados; proponer intercambio | Prototipo: Tiempo → Turnos y horarios |
| Políticas de jornada | Tolerancias, descansos, reglas de sobretiempo por nivel | Prototipo: Configuración → Centro de configuración → sección Configuración de cada módulo |

## 8. Datos principales

Jornadas, horarios (`work_schedules`), turnos (`shifts`), patrones de rotación, asignaciones de turno por día, políticas de tiempo, intercambios.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección TUR.

- **RN-TUR-01** (parámetro): Si un turno planificado hace superar la jornada máxima diaria o semanal (parámetro del paquete de país, a confirmar con la norma vigente) → se advierte o se bloquea según la política.
- **RN-TUR-02** (parámetro): Si entre el fin de un turno y el inicio del siguiente hay menos que el descanso mínimo (parámetro) → se advierte o se bloquea.
- **RN-TUR-03**: Si la persona tiene una ausencia o vacaciones aprobadas en la fecha → no se le puede asignar turno.
- **RN-TUR-04**: Si un plan de turnos no está publicado → no rige para el cálculo de asistencia ni se notifica al equipo.
- **RN-TUR-05**: Si se modifica un turno ya publicado → se notifica al afectado y queda historial del cambio.
- **RN-TUR-06** (parámetro): Si la cobertura de un turno queda por debajo del mínimo (parámetro por unidad) → se advierte al planificador.
- **RN-TUR-07**: Si una persona no tiene horario asignado en una fecha → hereda el de su grupo, sede o empresa, en ese orden.
- **RN-TUR-08**: Si un día es feriado según el calendario de su sede → no se espera asistencia salvo turno asignado, y el trabajo se califica como feriado trabajado.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `shift.assigned`, `shift.published`, `shift.changed`, `shift.swap_requested`, `shift.swap_approved`, `schedule.changed`.

**Consume:**

- `leave.approved`, `absence.approved`, `employee.hired`, `employee.terminated`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Turno partido con dos tramos el mismo día.
- Rotación que incluye turnos nocturnos consecutivos.
- Tiempo parcial con menos horas que el umbral del régimen.
- Teletrabajo parcial: días presenciales y remotos en el mismo horario.

## 14. Criterios de aceptación generales

- El planificador permite arrastrar, asignar en bloque y publicar; el colaborador ve su turno en el portal.
- Empresa, sede y grupo pueden tener políticas distintas con herencia.
