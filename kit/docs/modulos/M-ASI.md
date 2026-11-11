# M-ASI · Asistencia y marcación

Fase 2 · Tiempo · Épica `ASI` · Reglas `RN-ASI-nn` · Módulo comercial: Asistencia y turnos (`attendance`)

## 1. Objetivo

Registrar la asistencia por cualquier medio, de forma inalterable, y convertirla en horas trabajadas, tardanzas, faltas y sobretiempo que la planilla puede usar.

## 2. Alcance

- Marcación universal: web, aplicación con selfie y geocerca, kiosko, QR, PIN, cuadrilla (el supervisor marca al equipo), modo sin conexión, relojes biométricos por conector abierto, importación.
- Marcas de entrada, salida y descansos; cálculo diario contra el horario asignado: horas trabajadas, tardanza, salida anticipada, falta, horas extra, horas nocturnas, incidencias.
- Registro inalterable: las correcciones son registros nuevos con motivo y aprobación.
- Reporte legal de asistencia (DS 004-2006-TR) y cierre de asistencia por periodo hacia planilla.
- Personal no sujeto a control (dirección, confianza no fiscalizada) excluido por marca.

## 3. Fuera de alcance

- Definición de horarios y turnos (M-TUR). Aprobación y pago de horas extra (M-BHO). Control de acceso físico y comedor (integración futura).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §22 Asistencia; §85 Experiencia móvil.

**Correcciones que incorpora:** C-07 Auditoría, actividad y logs separados; asistencia inalterable.

**Ampliaciones que incorpora:** E-07 Marcación universal.

## 5. Actores y permisos

- Empleado marca y pide correcciones. Supervisor de cuadrilla marca al equipo. Manager revisa y aprueba correcciones. RRHH administra, cierra y reporta.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `time.punches.view` | Ver marcaciones | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.punches.create` | Crear marcaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.punches.create_for_crew` **(+)** | Marcar por los integrantes de su cuadrilla | Manager |
| `time.attendance_days.view` | Ver asistencia diaria calculada | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.attendance_days.close` | Cerrar asistencia diaria calculada | Administrador empresa, RRHH |
| `time.attendance_days.justify` | Justificar asistencia diaria calculada | Administrador empresa, RRHH, Manager |
| `time.attendance_days.recalculate` | Recalcular asistencia diaria calculada | Administrador empresa, RRHH |
| `time.attendance_days.reopen` **(+)** | Reabrir la asistencia de un periodo | Administrador empresa, RRHH |
| `time.attendance_days.export_legal` **(+)** | Generar el registro de asistencia para inspección | Administrador empresa, RRHH, Auditor |
| `time.attendance_corrections.create` **(+)** | Solicitar la corrección de una marca propia o registrarla por otro | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager, Empleado |
| `time.attendance_corrections.approve` **(+)** | Aprobar o rechazar correcciones | Administrador empresa, RRHH, Manager |
| `time.devices.view` | Ver kioskos, QR y relojes | Administrador empresa, RRHH, Auditor |
| `time.devices.create` | Crear kioskos, QR y relojes | Administrador empresa, RRHH |
| `time.devices.disable` | Deshabilitar kioskos, QR y relojes | Administrador empresa, RRHH |
| `time.devices.enable` | Habilitar kioskos, QR y relojes | Administrador empresa, RRHH |
| `time.devices.update` | Editar kioskos, QR y relojes | Administrador empresa, RRHH |
| `time.geofences.view` | Ver geocercas | Administrador empresa, RRHH, Auditor |
| `time.geofences.archive` | Archivar geocercas | Administrador empresa, RRHH |
| `time.geofences.create` | Crear geocercas | Administrador empresa, RRHH |
| `time.geofences.update` | Editar geocercas | Administrador empresa, RRHH |
| `time.punches.biometric.view` **(S)** **(+)** | Ver selfies y plantillas biométricas de marcación | Administrador empresa, RRHH |
| `time.punches.biometric.edit` **(S)** **(+)** | Administrar plantillas biométricas y su consentimiento | Administrador empresa, RRHH |
| `integrations.biometric.push` | Recibir marcas enviadas por relojes (credencial de dispositivo, sin rol) | — |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Marcación en aplicación.** 1) El colaborador pulsa «Marcar». 2) El dispositivo toma hora, ubicación y selfie si la política lo exige. 3) El servidor valida geocerca y registra la marca con su origen. 4) Si está fuera de geocerca o sin horario, la marca se guarda con incidencia para revisión.

**Sin conexión.** 1) El dispositivo guarda la marca firmada con su hora local y la del último sincronizado. 2) Al recuperar conexión, sincroniza. 3) El servidor conserva la hora del dispositivo y la de recepción y marca la diferencia.

**Cuadrilla.** 1) El supervisor elige su cuadrilla y marca entrada o salida de cada integrante (o de todos). 2) Cada marca guarda al supervisor como origen y su ubicación. 3) El integrante puede ver sus marcas y objetarlas.

**Corrección.** 1) El colaborador o RRHH solicita corregir (marca omitida, hora errónea) con motivo y sustento. 2) El aprobador decide. 3) Aprobada, se inserta una marca correctiva enlazada a la original; la original no cambia. 4) Se recalcula el día.

**Cierre.** 1) RRHH revisa incidencias del periodo. 2) Cierra la asistencia; se generan las incidencias para planilla. 3) Reabrir exige permiso y motivo, y solo si el periodo de planilla sigue abierto.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Marcar | Botón de entrada o salida, estado del día, última marca, mapa de geocerca, avisos sin conexión | Prototipo: Tiempo → Asistencia |
| Mi asistencia | Calendario del mes con marcas, horas, tardanzas e incidencias; pedir corrección | Prototipo: Tiempo → Asistencia |
| Asistencia del día | Presentes, ausentes, tardanzas, sin marca; filtros por sede y unidad | Prototipo: Tiempo → Asistencia |
| Detalle por colaborador | Marcas, origen, cálculo diario, correcciones enlazadas | Prototipo: Personas → Colaboradores → Ficha → pestaña Asistencia |
| Correcciones | Solicitudes pendientes con sustento; aprobar o rechazar | Prototipo: Tiempo → Asistencia |
| Cierre de asistencia | Incidencias por resolver, totales por persona, cerrar o reabrir | Prototipo: Tiempo → Asistencia |
| Cuadrilla | Lista del equipo con marcación individual o grupal | Prototipo: Tiempo → Asistencia |
| Kiosko | Pantalla compartida con QR, PIN o documento; cierre automático | Prototipo: Tiempo → Kiosko de marcación |
| Dispositivos y geocercas | Relojes, kioskos, QR por sede, radios de geocerca, estado de sincronización | Prototipo: Configuración → Centro de configuración → sección Configuración de cada módulo |

## 8. Datos principales

Marcaciones (`attendance_punches`, solo inserción, con origen, dispositivo, ubicación, foto opcional, hora de dispositivo y de servidor), correcciones, días calculados (`attendance_days`), dispositivos, geocercas, cuadrillas, cierres de asistencia, consentimientos biométricos.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ASI.

- **RN-ASI-01**: Si una marca se registra → no se puede editar ni borrar; cualquier ajuste es una marca correctiva nueva con motivo, autor y aprobación, enlazada a la original.
- **RN-ASI-02**: Si se genera el registro legal de asistencia → incluye razón social, RUC, nombre y documento del trabajador, fecha, hora y minutos de ingreso y salida y sobretiempo.
- **RN-ASI-03** (parámetro): Si un registro de asistencia tiene menos de cinco años (valor de referencia 2026, parámetro con vigencia) → no puede purgarse por la política de retención.
- **RN-ASI-04** (parámetro): Si la entrada ocurre después de la hora del horario más la tolerancia (parámetro por política) → se calcula tardanza en minutos desde la hora de inicio.
- **RN-ASI-05**: Si un día laborable del horario no tiene marcas ni ausencia aprobada → se registra falta injustificada al cerrar el día.
- **RN-ASI-06**: Si hay entrada sin salida al cerrar el día → se marca incidencia «marca incompleta»; no se asume hora de salida.
- **RN-ASI-07** (parámetro): Si la marca se hace fuera de la geocerca de la sede asignada → se acepta con incidencia o se rechaza según la política (parámetro).
- **RN-ASI-08** (parámetro): Si la política exige selfie o biometría → no se admite la marca sin ella y el colaborador debe tener consentimiento vigente; sin consentimiento se ofrece un método alternativo.
- **RN-ASI-09**: Si una marca llega de un dispositivo sin conexión → se conserva la hora del dispositivo y la de recepción; una diferencia de reloj mayor que la tolerancia genera incidencia.
- **RN-ASI-10**: Si el colaborador está marcado como no sujeto a control de asistencia → no se le calculan tardanzas, faltas ni horas extra.
- **RN-ASI-11**: Si el tiempo trabajado excede la jornada del horario → el exceso se registra como «sobretiempo por calificar» y solo se convierte en horas extra pagables o compensables cuando se aprueba (M-BHO).
- **RN-ASI-12** (parámetro): Si las horas trabajadas caen en la franja nocturna (parámetro del paquete de país) → se contabilizan como horas nocturnas para la planilla.
- **RN-ASI-13**: Si la asistencia del periodo está cerrada → no se admiten correcciones salvo reapertura con permiso y motivo.
- **RN-ASI-14**: Si dos marcas del mismo tipo llegan dentro de la ventana de duplicado (parámetro) → la segunda se guarda marcada como duplicada y no entra al cálculo.

## 10. Estados

- ME-13 · Marcación (`05-maquinas-de-estado.md`): estados Registrada, Con incidencia, Validada, Reemplazada por corrección, Duplicada.
- ME-14 · Corrección de marcación (`05-maquinas-de-estado.md`): estados Solicitada, Aprobada, Rechazada, Cancelada.

## 11. Eventos

**Emite:**

- `attendance.punched`, `attendance.correction_requested`, `attendance.correction_approved`, `attendance.day_calculated`, `attendance.absence_detected`, `attendance.period_closed`, `attendance.period_reopened`.

**Consume:**

- `leave.approved`, `absence.approved`, `shift.assigned`, `employment.schedule_changed`, `holiday.changed`.

## 12. Integraciones

Conectores a relojes biométricos (API abierta y archivos), geolocalización, detección de vida para selfie; todos por adaptador.

## 13. Casos borde

- Turno que cruza la medianoche: la jornada pertenece al día de inicio.
- Cambio de sede en el día: dos geocercas válidas.
- Feriado trabajado y día de descanso trabajado.
- Reloj biométrico que envía marcas con días de retraso tras el cierre.
- Trabajador con dos relaciones: marca por relación.

## 14. Criterios de aceptación generales

- Ninguna operación de la aplicación o de la API modifica una marca existente.
- El reporte legal se genera por trabajador y periodo con los campos exigidos y la bitácora de correcciones.
- Los seis métodos de marcación producen el mismo tipo de registro.
