# M-AUS · Ausencias y permisos

Fase 2 · Tiempo · Épica `AUS` · Reglas `RN-AUS-nn` · Módulo comercial: Vacaciones y ausencias (`time_off`)

## 1. Objetivo

Gestionar todo tipo de ausencia distinta de las vacaciones con reglas propias por tipo y con su efecto correcto en asistencia y planilla.

## 2. Alcance

- Tipos configurables: descanso médico, licencias (maternidad, paternidad, fallecimiento, otras de ley), permiso personal, compensación, trabajo remoto, licencia sindical, suspensión, otros.
- Reglas por tipo: con o sin goce, quién aprueba, sustento obligatorio, máximo de días, anticipación, cómputo, efecto en planilla, subsidio, confidencialidad.
- Descansos médicos: certificado, días acumulados en el año, paso a subsidio, seguimiento de reembolso.
- Permisos por horas.

## 3. Fuera de alcance

- Vacaciones (M-VAC). Compensación de horas (M-BHO define el saldo; aquí se registra el día libre).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §26 Ausencias y permisos.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-20 Teletrabajo (Ley 31572).

## 5. Actores y permisos

- Empleado solicita. Manager aprueba. RRHH valida sustento y registra las de ley. Payroll ve el efecto.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `time.absences.view` | Ver ausencias y permisos (sin diagnóstico) | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.absences.approve` | Aprobar ausencias y permisos (sin diagnóstico) | Administrador empresa, RRHH, Manager |
| `time.absences.cancel` | Cancelar ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.create` | Crear ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.reject` | Rechazar ausencias y permisos (sin diagnóstico) | Administrador empresa, RRHH, Manager |
| `time.absences.submit` | Enviar ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.update` | Editar ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.create_for_others` **(+)** | Registrar ausencias a nombre de otros | Administrador empresa, RRHH |
| `time.absences.manage_subsidy` **(+)** | Gestionar subsidios y reembolsos | Administrador empresa, RRHH, Payroll |
| `time.absence_types.view` | Ver tipos de ausencia y sus reglas | Administrador empresa, RRHH, Payroll, Manager, Empleado, Auditor |
| `time.absence_types.archive` | Archivar tipos de ausencia y sus reglas | Administrador empresa, RRHH |
| `time.absence_types.create` | Crear tipos de ausencia y sus reglas | Administrador empresa, RRHH |
| `time.absence_types.update` | Editar tipos de ausencia y sus reglas | Administrador empresa, RRHH |
| `time.absences.medical_certificate.view` **(S)** **(+)** | Ver certificados médicos y sustentos confidenciales | RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Solicitud.** 1) El colaborador elige tipo y fechas u horas. 2) Adjunta sustento si el tipo lo exige. 3) El sistema valida reglas del tipo y traslapes. 4) Aprobación según el tipo. 5) Aprobada, actualiza calendario, asistencia e incidencias de planilla.

**Descanso médico.** 1) Se registra con certificado. 2) El sistema suma los días del año. 3) Al superar el umbral a cargo del empleador, marca los días siguientes como subsidio. 4) Payroll gestiona el reembolso y registra su estado.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Ausencias | Lista con tipo, fechas, estado y efecto en planilla; registrar, aprobar | Prototipo: Tiempo → Ausencias y permisos |
| Solicitar permiso | Tipo, fechas u horas, sustento, saldo si aplica | Prototipo: Tiempo → Ausencias y permisos |
| Tipos de ausencia | Catálogo con reglas por tipo | Prototipo: Configuración → Centro de configuración → sección Configuración de cada módulo |
| Subsidios | Descansos en subsidio con días, entidad, monto y estado de reembolso | Prototipo: Tiempo → Ausencias y permisos |

## 8. Datos principales

Tipos de ausencia y reglas, ausencias (`absence_requests`), sustentos, acumulados anuales de descanso médico, expedientes de subsidio.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección AUS.

- **RN-AUS-01**: Si el tipo exige sustento → no se aprueba sin el documento adjunto (se puede registrar en estado «pendiente de sustento» por el plazo configurado).
- **RN-AUS-02** (parámetro): Si el tipo es sin goce de haber → genera descuento proporcional en planilla y, según el paquete de país, no computa para ciertos beneficios (parámetro por tipo).
- **RN-AUS-03** (parámetro): Si los días de descanso médico del año superan los que asume el empleador (parámetro del paquete de país, a confirmar con la norma vigente) → los días siguientes se tratan como subsidio y se separan en planilla.
- **RN-AUS-04** (parámetro): Si el tipo tiene duración fijada por ley (maternidad, paternidad, fallecimiento; parámetros del paquete de país, a confirmar) → el sistema propone la duración y no permite menos.
- **RN-AUS-05**: Si el tipo es confidencial (salud) → el jefe ve «ausencia justificada» y fechas, nunca el diagnóstico ni el certificado.
- **RN-AUS-06**: Si una ausencia aprobada se solapa con marcas de asistencia → se genera incidencia para que RRHH resuelva.
- **RN-AUS-07** (parámetro): Si la ausencia supera el máximo de días u horas del tipo en el periodo (parámetro) → requiere excepción de RRHH.
- **RN-AUS-08**: Si la ausencia cae en un periodo de planilla cerrado → solo RRHH la registra y su efecto va como ajuste al periodo abierto.
- **RN-AUS-09**: Si se registra «trabajo remoto» ocasional → no descuenta ni afecta asistencia; exige marcación remota si la política lo pide.

## 10. Estados

- ME-16 · Ausencia (`05-maquinas-de-estado.md`): estados Solicitada, Pendiente de sustento, Aprobada, En curso, Finalizada, Rechazada, Cancelada.

## 11. Eventos

**Emite:**

- `absence.requested`, `absence.approved`, `absence.rejected`, `absence.cancelled`, `absence.subsidy_started`, `absence.ended`.

**Consume:**

- `approval.approved`, `sst.incident_reported` (descanso por accidente de trabajo), `payroll.closed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Descanso médico que se extiende con un nuevo certificado contiguo.
- Licencia de maternidad que empieza antes de la fecha prevista.
- Permiso por horas que convierte un día en jornada parcial.
- Accidente de trabajo cubierto por SCTR.

## 14. Criterios de aceptación generales

- Cada tipo puede tener reglas propias y su efecto llega a asistencia y planilla sin doble digitación.
