# M-VAC · Vacaciones

Fase 2 · Tiempo · Épica `VAC` · Reglas `RN-VAC-nn` · Módulo comercial: Vacaciones y ausencias (`time_off`)

## 1. Objetivo

Llevar el saldo de vacaciones de cada relación laboral con exactitud y resolver las solicitudes con un flujo simple que actualiza todo lo conectado.

## 2. Alcance

- Política por régimen y empresa: días por año, devengo, requisitos, fraccionamiento, adelanto, acumulación, venta de días.
- Saldo: generadas, tomadas, programadas, pendientes, disponibles, vencidas y truncas, por periodo vacacional.
- Solicitud, aprobación por jefe y validación de RRHH si corresponde; programación anual; calendario del equipo.
- Vacaciones colectivas; interrupción por descanso médico; reprogramación y cancelación.
- Alertas de acumulación y de periodos por vencer; provisión (M-PRV) y remuneración vacacional (M-BSO).

## 3. Fuera de alcance

- Otras ausencias (M-AUS). Cálculo del pago (M-BSO, M-PER).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §25 Vacaciones.

**Correcciones que incorpora:** C-11 Una sola fuente de verdad.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Empleado solicita. Manager aprueba. RRHH valida, programa y ajusta saldos.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `time.vacation_requests.view` | Ver solicitudes de vacaciones | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.vacation_requests.approve` | Aprobar solicitudes de vacaciones | Administrador empresa, RRHH, Manager |
| `time.vacation_requests.cancel` | Cancelar solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.create` | Crear solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.reject` | Rechazar solicitudes de vacaciones | Administrador empresa, RRHH, Manager |
| `time.vacation_requests.submit` | Enviar solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.update` | Editar solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.validate` **(+)** | Validación final de RRHH | Administrador empresa, RRHH |
| `time.vacation_requests.schedule` **(+)** | Programar el rol anual y vacaciones colectivas | Administrador empresa, RRHH, Manager |
| `time.vacation_balances.view` | Ver saldos de vacaciones | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.vacation_balances.adjust` | Ajustar saldos de vacaciones | Administrador empresa, RRHH |
| `time.vacation_policies.update` **(+)** | Configurar políticas de vacaciones | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Solicitud.** 1) El colaborador ve su saldo por periodo y elige fechas. 2) El sistema calcula días a descontar, valida saldo, anticipación, fraccionamiento, traslapes y cierre de planilla. 3) Se envía al jefe. 4) Si la política lo exige, RRHH valida. 5) Aprobada: se reserva el saldo («programadas»), se marca el calendario, se bloquean turnos y se genera la incidencia de planilla. 6) Al llegar las fechas pasa a «En goce» y luego «Gozada»; el saldo pasa de programado a tomado.

**Cancelación o reprogramación.** 1) Antes del inicio, el colaborador o RRHH cancela; el saldo vuelve a disponible. 2) Iniciado el goce, solo RRHH puede interrumpir con motivo (p. ej. descanso médico) y los días no gozados vuelven al saldo.

**Programación anual.** 1) RRHH abre la campaña. 2) Cada jefe propone fechas para su equipo. 3) Los colaboradores confirman. 4) Se crean solicitudes aprobadas.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Mis vacaciones | Saldo por periodo (generadas, tomadas, programadas, disponibles, por vencer), historial, solicitar | Prototipo: Tiempo → Vacaciones |
| Solicitudes de vacaciones | Bandeja con estado, días, saldo resultante y traslapes; aprobar o rechazar | Prototipo: Tiempo → Vacaciones |
| Calendario de ausencias | Vista del equipo o de la unidad por mes | Prototipo: Tiempo → Vacaciones |
| Saldos | Tabla por colaborador y periodo; ajustar con motivo; exportar | Prototipo: Tiempo → Vacaciones |
| Vacaciones del colaborador | Saldos e historial en la ficha | Prototipo: Personas → Colaboradores → Ficha → pestaña Vacaciones |
| Políticas de vacaciones | Reglas por régimen y empresa con vigencia | Prototipo: Configuración → Centro de configuración → sección Configuración de cada módulo |

## 8. Datos principales

Políticas de vacaciones, periodos vacacionales por relación, movimientos de saldo (libro mayor: devengo, goce, ajuste, venta, vencimiento), solicitudes (`vacation_requests`), programación anual.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección VAC.

- **RN-VAC-01** (parámetro): Si la relación está en régimen general → devenga los días anuales del régimen (parámetro del paquete de país, a confirmar con la norma vigente); si es micro o pequeña empresa → 15 días por año (valor de referencia 2026, parámetro con vigencia).
- **RN-VAC-02**: Si se consulta el saldo → se calcula sumando los movimientos del libro de vacaciones; no existe un campo de saldo editable.
- **RN-VAC-03**: Si se aprueba una solicitud → los días pasan de «disponibles» a «programadas» en el mismo acto, y calendario, tablero e incidencias de planilla se actualizan.
- **RN-VAC-04** (parámetro): Si los días solicitados superan el saldo disponible más el adelanto permitido por la política (parámetro) → se rechaza el envío.
- **RN-VAC-05** (parámetro): Si la solicitud no respeta la anticipación mínima o el fraccionamiento mínimo de la política (parámetros; el fraccionamiento legal a confirmar con la norma vigente) → se rechaza o se pide excepción a RRHH.
- **RN-VAC-06**: Si las fechas se solapan con otra ausencia aprobada de la misma persona → se rechaza.
- **RN-VAC-07** (parámetro): Si el rango incluye días no laborables → se cuentan o no según la regla de cómputo de la política (días calendario o hábiles; parámetro).
- **RN-VAC-08**: Si las fechas caen en un periodo de planilla cerrado → no se puede aprobar ni cancelar sin el flujo de ajuste de RRHH.
- **RN-VAC-09** (parámetro): Si un periodo vacacional no se goza dentro del año siguiente a su generación (regla y efecto a confirmar con la norma vigente) → se marca «vencido», se alerta y se calcula la indemnización vacacional según el paquete de país.
- **RN-VAC-10** (parámetro): Si una persona acumula más periodos que el máximo de la política → se alerta a su jefe y a RRHH con explicación.
- **RN-VAC-11**: Si durante el goce se registra un descanso médico → las vacaciones no se interrumpen de forma automática; RRHH decide según política y los días devueltos se registran como movimiento.
- **RN-VAC-12**: Si la relación cesa → los días pendientes y truncos pasan a la liquidación (M-LIQ) y el saldo queda en cero.
- **RN-VAC-13**: Si RRHH ajusta un saldo → exige motivo y sustento y queda como movimiento auditable.

## 10. Estados

- ME-15 · Solicitud de vacaciones (`05-maquinas-de-estado.md`): estados Borrador, Pendiente de jefe, En validación de RRHH, Aprobada (programada), En goce, Gozada, Rechazada, Cancelada.

## 11. Eventos

**Emite:**

- `leave.requested`, `leave.approved`, `leave.rejected`, `leave.cancelled`, `leave.started`, `leave.completed`, `leave.balance_adjusted`, `leave.period_expiring`.

**Consume:**

- `employee.hired` (abre el primer periodo), `employee.terminated`, `approval.approved`, `approval.rejected`, `payroll.closed`, `employment.regime_changed`.

## 12. Integraciones

Calendario externo (M-INT). WhatsApp (M-WHA).

## 13. Casos borde

- Ingreso a mitad de mes: devengo proporcional.
- Cambio de régimen MYPE a general: días por año cambian desde la vigencia.
- Tiempo parcial por debajo del umbral del régimen: derecho según paquete de país.
- Venta de días (reducción de descanso) con tope del paquete de país.
- Solicitud que cruza dos periodos vacacionales: consume primero el más antiguo.
- Días que no computan para el récord vacacional (faltas, licencias sin goce) según el paquete de país.

## 14. Criterios de aceptación generales

- Aprobar vacaciones actualiza solicitud, saldo, calendario, tablero y actividad, y deja la incidencia lista para planilla.
- El saldo muestra generadas, tomadas, programadas, pendientes y disponibles y coincide con el libro de movimientos.
