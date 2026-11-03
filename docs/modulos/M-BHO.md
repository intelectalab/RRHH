# M-BHO · Horas extra y bolsa de horas

Fase 2 · Tiempo · Épica `BHO` · Reglas `RN-BHO-nn` · Módulo comercial: Asistencia y turnos (`attendance`)

## 1. Objetivo

Controlar el sobretiempo: que se autorice, se califique con la sobretasa correcta y se pague o se compense con descanso, llevando un saldo claro.

## 2. Alcance

- Autorización previa o regularización posterior de horas extra.
- Calificación por tramos (primeras horas, siguientes, nocturnas, feriado, día de descanso) con sobretasas del paquete de país.
- Destino: pago en planilla o compensación; acuerdo de compensación por escrito.
- Bolsa de horas: saldo, vencimiento, solicitud de día u horas compensatorias.
- Topes y alertas por persona y por unidad.

## 3. Fuera de alcance

- Detección del exceso (M-ASI). Cálculo en boleta (M-PER).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-06 Bolsa de horas.

## 5. Actores y permisos

- Empleado registra o solicita. Manager autoriza. RRHH califica y controla. Payroll paga.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `time.overtime_requests.view` | Ver horas extra | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.overtime_requests.approve` | Aprobar horas extra | Administrador empresa, RRHH, Manager |
| `time.overtime_requests.cancel` | Cancelar horas extra | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager, Empleado |
| `time.overtime_requests.create` | Crear horas extra | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager, Empleado |
| `time.overtime_requests.reject` | Rechazar horas extra | Administrador empresa, RRHH, Manager |
| `time.time_bank.view` | Ver la bolsa de horas | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.time_bank.create` | Crear la bolsa de horas | Administrador empresa, RRHH |
| `time.overtime_policies.update` **(+)** | Configurar reglas de sobretiempo y compensación | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Autorización previa.** 1) El jefe o el colaborador registra la necesidad (fecha, horas, motivo). 2) Se aprueba. 3) Tras la jornada, asistencia confirma las horas reales. 4) Se califican por tramo. 5) Según el destino, van a planilla o a la bolsa.

**Regularización.** 1) Asistencia detecta sobretiempo no autorizado. 2) El jefe lo reconoce o lo rechaza con motivo. 3) Reconocido, sigue el flujo anterior.

**Compensación.** 1) El colaborador pide usar horas de su bolsa. 2) El jefe aprueba. 3) Se registra la ausencia compensatoria y se descuenta el saldo.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Horas extra | Solicitudes y sobretiempo detectado con estado, horas, tramo y destino; aprobar | Prototipo: Tiempo → Horas extra y bolsa |
| Bolsa de horas | Saldo por persona, movimientos, vencimientos; ajustar | Prototipo: Tiempo → Horas extra y bolsa |
| Mi bolsa | Saldo propio, movimientos, solicitar compensación | Prototipo: Tiempo → Horas extra y bolsa |

## 8. Datos principales

Solicitudes de horas extra (`overtime_requests`), calificación por tramo, movimientos de bolsa de horas (libro), acuerdos de compensación.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección BHO.

- **RN-BHO-01**: Si el sobretiempo no está aprobado → no se paga ni se acredita; permanece «por calificar» y visible para RRHH (el trabajo efectivo no reconocido es un riesgo que se alerta).
- **RN-BHO-02** (parámetro): Si se califican horas extra → las dos primeras del día llevan la sobretasa del primer tramo y las siguientes la del segundo (parámetros del paquete de país, a confirmar con la norma vigente).
- **RN-BHO-03** (parámetro): Si el destino es compensación → requiere acuerdo aceptado por el colaborador y las horas se acreditan a la bolsa con la equivalencia de la política (parámetro).
- **RN-BHO-04** (parámetro): Si las horas de la bolsa no se usan dentro del plazo de la política (parámetro) → se pagan en la planilla siguiente con su sobretasa; no se pierden.
- **RN-BHO-05** (parámetro): Si la persona supera el tope de horas extra del periodo (parámetro por política) → se bloquea una nueva autorización o se exige nivel superior.
- **RN-BHO-06**: Si el colaborador no está sujeto a control de asistencia → no se le registran horas extra.
- **RN-BHO-07**: Si el trabajo ocurre en feriado o día de descanso sin descanso sustitutorio → se califica con la regla propia de esos días del paquete de país.
- **RN-BHO-08**: Si las horas aprobadas difieren de las realmente marcadas → se pagan o acreditan las efectivamente trabajadas hasta el máximo aprobado; el exceso vuelve a «por calificar».
- **RN-BHO-09**: Si la relación cesa con saldo en la bolsa → el saldo se paga en la liquidación.

## 10. Estados

- ME-17 · Horas extra (`05-maquinas-de-estado.md`): estados Solicitada, Autorizada, Por calificar, Calificada, En planilla, En bolsa de horas, Rechazada.

## 11. Eventos

**Emite:**

- `overtime.requested`, `overtime.approved`, `overtime.rejected`, `overtime.qualified`, `time_bank.credited`, `time_bank.used`, `time_bank.expiring`.

**Consume:**

- `attendance.day_calculated`, `approval.approved`, `payroll.closed`, `employee.terminated`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Sobretiempo que cruza la medianoche hacia un feriado.
- Tiempo parcial que supera su jornada pactada sin llegar a la jornada completa.
- Compensación pedida con saldo aún no confirmado por asistencia.

## 14. Criterios de aceptación generales

- El saldo de la bolsa cuadra con sus movimientos y las horas pagadas aparecen en la boleta con su tramo.
