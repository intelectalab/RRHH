# M-PER · Periodos, incidencias, cálculo, simulación y cierre

Fase 3 · Nómina Perú · Épica `PER` · Reglas `RN-PER-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Conducir cada planilla por su proceso completo, con control y segregación de funciones: periodo → incidencias → cálculo → revisión → aprobación → cierre → boletas → pago → contabilización.

## 2. Alcance

- Periodos por empresa, grupo y tipo de proceso; calendario de pago.
- Incidencias: novedades del periodo (bonos, comisiones, descuentos, días) por digitación, importación o automáticas desde tiempo, ausencias, préstamos y adelantos.
- Cálculo y recálculo; simulación antes de cerrar; comparación mes anterior, mes actual y variación; detección de anomalías con explicación.
- Revisión por excepción, aprobación por persona distinta, cierre, reapertura controlada.
- Pago (lote bancario) y marca de pagado; ajustes y retroactivos.

## 3. Fuera de alcance

- Fórmulas y parámetros. Archivos de declaración y banco (M-DEC). Asientos (M-PRV).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §27 Planillas / payroll; §30 Simulador de planilla.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Payroll prepara y calcula. Aprobador de planilla (Administrador u otro rol) aprueba. Contador consulta. Auditor revisa.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.results.view` **(S)** | Ver resultados de planilla por persona y concepto | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.periods.view` | Ver periodos de planilla | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.periods.approve` | Aprobar periodos de planilla | Administrador empresa |
| `payroll.periods.calculate` | Calcular periodos de planilla | Administrador empresa, Payroll |
| `payroll.periods.close` | Cerrar periodos de planilla | Administrador empresa, Payroll |
| `payroll.periods.create` | Crear periodos de planilla | Administrador empresa, Payroll |
| `payroll.periods.mark_paid` | Marcar como pagado periodos de planilla | Administrador empresa, Payroll |
| `payroll.periods.reject` | Rechazar periodos de planilla | Administrador empresa |
| `payroll.periods.reopen` | Reabrir periodos de planilla | Administrador empresa |
| `payroll.periods.simulate` | Simular periodos de planilla | Administrador empresa, Payroll |
| `payroll.periods.submit` | Enviar periodos de planilla | Administrador empresa, Payroll |
| `payroll.incidents.view` | Ver incidencias de planilla | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.incidents.create` | Crear incidencias de planilla | Administrador empresa, RRHH, Payroll |
| `payroll.incidents.delete` | Eliminar incidencias de planilla | Administrador empresa, RRHH, Payroll |
| `payroll.incidents.update` | Editar incidencias de planilla | Administrador empresa, RRHH, Payroll |
| `payroll.incidents.approve` **(+)** | Aprobar incidencias que requieren visto bueno | Administrador empresa, RRHH, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ciclo mensual.** 1) Se abre el periodo. 2) Llegan incidencias automáticas (asistencia cerrada, ausencias, horas extra, cuotas de préstamos, adelantos) y Payroll carga las manuales. 3) Calcula. 4) Revisa por excepción: variaciones, anomalías, errores. 5) Corrige y recalcula. 6) Envía a aprobación. 7) Una persona distinta aprueba o devuelve con observaciones. 8) Cierre: los resultados quedan inmutables. 9) Se generan boletas, archivo de pago, declaraciones y asiento. 10) Se registra el pago.

**Simulación.** 1) Sin cerrar, Payroll calcula y ve el comparativo. 2) Las anomalías llevan explicación («neto cayó 32 %: 6 días de licencia sin goce y fin de bono temporal»). 3) Puede descartar o marcar cada alerta como revisada.

**Reapertura.** 1) Solo antes de declarar o pagar, con permiso y motivo. 2) Después, toda corrección va por ajuste en el periodo siguiente o por rectificatoria.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Periodos | Lista con tipo, fechas, estado, totales (con permiso), responsable; abrir periodo | Prototipo: Nómina → Planilla |
| Incidencias | Novedades del periodo por persona y concepto con origen; cargar, importar, aprobar | Prototipo: Nómina → Planilla |
| Cálculo | Resultado por persona con ingresos, descuentos, aportes y neto; errores; recalcular | Prototipo: Nómina → Planilla |
| Simulación y comparativo | Mes anterior | mes actual | variación por persona y concepto; anomalías explicadas | Prototipo: Nómina → Planilla |
| Aprobación del periodo | Resumen, variaciones, anomalías revisadas, observaciones; aprobar o devolver | Prototipo: Nómina → Planilla |
| Cierre y pagos | Lista de comprobación de cierre, generar boletas y archivos, registrar pago | Prototipo: Nómina → Planilla |
| Planilla del colaborador | Resultados por periodo en la ficha | Prototipo: Personas → Colaboradores → Ficha → pestaña Planilla |

## 8. Datos principales

Periodos (`payroll_periods`), incidencias (`payroll_incidents`) con origen, ejecuciones, resultados, anomalías y su revisión, aprobaciones, lotes de pago, ajustes.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PER.

- **RN-PER-01**: Si un usuario calculó o modificó incidencias de un periodo → no puede aprobar ese mismo periodo, aunque tenga el permiso (segregación de funciones).
- **RN-PER-02**: Si el periodo no está «Aprobado» → no se puede cerrar.
- **RN-PER-03**: Si el periodo está «Cerrado» → sus resultados, incidencias y boletas no se modifican; las correcciones van como ajuste al periodo abierto.
- **RN-PER-04**: Si existen relaciones «con error» de cálculo → el periodo no puede enviarse a aprobación.
- **RN-PER-05** (parámetro): Si el neto de una persona varía respecto al periodo anterior más que el umbral (parámetro, por defecto configurable por empresa) → se genera una anomalía con las causas calculadas (conceptos que explican la diferencia).
- **RN-PER-06**: Si una anomalía no está marcada como revisada → el envío a aprobación lo advierte y exige confirmación.
- **RN-PER-07**: Si la asistencia del periodo no está cerrada → el cálculo definitivo lo advierte; solo se permite simular.
- **RN-PER-08**: Si se reabre un periodo → exige motivo, permiso específico y que no esté declarado ni pagado; las boletas publicadas se retiran y se notifica.
- **RN-PER-09**: Si una incidencia viene de otro módulo (ausencia, horas extra, cuota) → no se edita en planilla; se corrige en su origen.
- **RN-PER-10** (parámetro): Si una incidencia manual supera el monto que requiere visto bueno (parámetro) → necesita aprobación antes de entrar al cálculo.
- **RN-PER-11**: Si el periodo anterior del mismo grupo no está cerrado → no se puede cerrar el siguiente.
- **RN-PER-12**: Si se registra el pago → se guarda fecha, medio y lote; el periodo pasa a «Pagado» y se emite `payroll.paid`.
- **RN-PER-13**: Si se cierra el periodo → se disparan en orden boletas, archivo de pago, provisiones y asiento; un fallo en uno no revierte el cierre y queda como pendiente visible.
- **RN-PER-14**: Si el grupo de planilla es semanal o quincenal → cada periodo de pago lleva el periodo tributario al que consolida; la retención de quinta y el aporte de salud se determinan por mes tributario.

## 10. Estados

- ME-19 · Periodo de planilla (`05-maquinas-de-estado.md`): estados Abierto, Calculado, En aprobación, Aprobado, Cerrado, Pagado, Contabilizado.

## 11. Eventos

**Emite:**

- `payroll.period_opened`, `payroll.calculated`, `payroll.submitted`, `payroll.approved`, `payroll.returned`, `payroll.closed`, `payroll.reopened`, `payroll.paid`, `payroll.anomaly_detected`.

**Consume:**

- `attendance.period_closed`, `overtime.qualified`, `absence.approved`, `leave.approved`, `loan.installment_due`, `advance.paid`, `expense.approved_for_payroll`, `payroll.retro_difference_created`.

## 12. Integraciones

Bancos (archivo o API de pagos), contabilidad (M-PRV, M-INT).

## 13. Casos borde

- Ingreso registrado después del cálculo y antes del cierre.
- Cese con liquidación dentro del periodo.
- Planilla de una empresa con dos regímenes.
- Segundo cálculo tras devolución del aprobador.
- Pago parcial rechazado por el banco para algunas cuentas.

## 14. Criterios de aceptación generales

- El proceso recorre periodo, incidencias, cálculo, revisión, aprobación, cierre, boletas, pago y contabilización con un estado visible en cada paso.
- Quien calcula no puede aprobar, comprobado por prueba automática.
- El simulador muestra mes anterior, mes actual y variación, y explica cada anomalía.
