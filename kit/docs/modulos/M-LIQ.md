# M-LIQ · Liquidación de beneficios sociales

Fase 3 · Nómina Perú · Épica `LIQ` · Reglas `RN-LIQ-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Calcular y documentar lo que corresponde al trabajador al terminar su relación, con un simulador previo y los documentos de cese.

## 2. Alcance

- Conceptos: remuneración pendiente, CTS trunca, gratificación trunca y su bonificación, vacaciones truncas y no gozadas, indemnización vacacional, indemnización por despido según régimen y causa, bolsa de horas, descuentos (préstamos, adelantos, activos no devueltos según política).
- Simulador con fecha y motivo hipotéticos.
- Aprobación, pago y firma.
- Documentos de cese: liquidación, certificado de trabajo, carta de liberación de CTS, constancia de cese, certificado de rentas y retenciones (disponible; su entrega no es obligatoria).
- Baja en T-Registro y seguros.

## 3. Fuera de alcance

- El proceso de salida (M-OFF). La decisión de cesar.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §17 Offboarding; §28 Localización Perú.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-04 Liquidación de beneficios sociales con simulador.

## 5. Actores y permisos

- Payroll calcula. Aprobador aprueba. RRHH entrega documentos. Empleado firma y recibe.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.settlements.view` **(S)** | Ver liquidaciones de beneficios sociales | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `payroll.settlements.approve` **(S)** | Aprobar liquidaciones de beneficios sociales | Administrador empresa |
| `payroll.settlements.calculate` **(S)** | Calcular liquidaciones de beneficios sociales | Administrador empresa, Payroll |
| `payroll.settlements.create` **(S)** | Crear liquidaciones de beneficios sociales | Administrador empresa, Payroll |
| `payroll.settlements.mark_paid` **(S)** | Marcar como pagado liquidaciones de beneficios sociales | Administrador empresa, Payroll |
| `payroll.settlements.simulate` **(S)** | Simular liquidaciones de beneficios sociales | Administrador empresa, Payroll, RRHH |
| `payroll.settlements.update` **(S)** | Editar liquidaciones de beneficios sociales | Administrador empresa, Payroll |
| `payroll.settlements.void` **(S)** | Anular liquidaciones de beneficios sociales | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Liquidación.** 1) El cese registrado crea la liquidación en borrador. 2) El motor calcula con datos a la fecha de cese. 3) Payroll revisa y ajusta incidencias. 4) Aprobación por persona distinta. 5) Pago dentro del plazo legal. 6) Se generan y firman los documentos; se entregan en el portal. 7) Se crean las novedades de baja.

**Simulación.** 1) Se elige persona, fecha y motivo. 2) El sistema muestra el detalle por concepto. 3) No guarda efectos; queda registro de quién simuló.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Liquidaciones | Lista con persona, fecha de cese, estado, total (con permiso), plazo de pago | Prototipo: Nómina → Beneficios sociales → pestaña Liquidación de beneficios |
| Detalle de liquidación | Conceptos con base, tiempo y fórmula; documentos; pago | Prototipo: Nómina → Beneficios sociales → pestaña Liquidación de beneficios |
| Simulador | Persona, fecha, motivo; resultado por concepto | Prototipo: Nómina → Beneficios sociales → pestaña Liquidación de beneficios |

## 8. Datos principales

Liquidaciones (`settlements`) y líneas, simulaciones, documentos de cese, pagos.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección LIQ.

- **RN-LIQ-01**: Si se registra un cese → se crea la liquidación en borrador con la fecha y el motivo del cese.
- **RN-LIQ-02** (parámetro): Si se paga la liquidación → debe hacerse dentro del plazo legal desde el cese (parámetro del paquete de país, a confirmar con la norma vigente); el sistema alerta antes de vencer.
- **RN-LIQ-03** (parámetro): Si corresponde indemnización por despido → se calcula con la fórmula y el tope del régimen de la relación (parámetros del paquete de país, a confirmar con la norma vigente) y solo cuando el motivo de cese la genera.
- **RN-LIQ-04**: Si hay periodos vacacionales no gozados o truncos → se liquidan según la regla del régimen y el saldo de vacaciones queda en cero.
- **RN-LIQ-05**: Si existen saldos de préstamos o adelantos → se descuentan hasta el límite permitido y el remanente se documenta.
- **RN-LIQ-06**: Si quien calculó la liquidación intenta aprobarla → se rechaza (segregación de funciones).
- **RN-LIQ-07**: Si la liquidación se aprueba → no se modifica; un error posterior se corrige con liquidación complementaria.
- **RN-LIQ-08**: Si se paga la liquidación → se emiten certificado de trabajo, carta de liberación de CTS y constancia de cese, y se generan las bajas en T-Registro y seguros.
- **RN-LIQ-09**: Si se simula → no se crea ningún registro de cese ni se notifica al trabajador; la simulación queda en auditoría como acceso sensible.
- **RN-LIQ-10**: Si el motivo es fallecimiento → el pago se registra a favor de los beneficiarios declarados y se bloquea hasta contar con el sustento.
- **RN-LIQ-11**: Si se liquida un cese → se calcula el ajuste final de quinta con lo realmente percibido; si resulta exceso, se devuelve en la liquidación y se registra para su compensación.

## 10. Estados

- ME-23 · Liquidación de beneficios (`05-maquinas-de-estado.md`): estados Borrador, Calculada, En aprobación, Aprobada, Pagada, Documentos entregados, Anulada.

## 11. Eventos

**Emite:**

- `settlement.created`, `settlement.calculated`, `settlement.approved`, `settlement.paid`, `settlement.documents_issued`.

**Consume:**

- `employee.terminated`, `offboarding.started`, `asset.returned`, `loan.paid_off`.

## 12. Integraciones

M-DEC (bajas), M-DOC (firma), bancos.

## 13. Casos borde

- Cese el mismo día del ingreso o dentro del periodo de prueba.
- Cese con periodo de planilla del mes aún abierto.
- Reingreso posterior: nueva relación, antigüedad según regla.
- Liquidación de modalidad formativa o de locador: reglas distintas.

## 14. Criterios de aceptación generales

- El simulador muestra truncos, vacaciones no gozadas e indemnización con su traza, y la liquidación aprobada emite los documentos de cese.
