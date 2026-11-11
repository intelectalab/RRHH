# M-PRE · Préstamos, adelantos y adelanto de sueldo

Fase 3 · Nómina Perú · Épica `PRE` · Reglas `RN-PRE-nn` · Módulo comercial: Préstamos y adelanto de sueldo (`advances`)

## 1. Objetivo

Administrar préstamos y adelantos al personal con cronograma y descuento automático, y ofrecer adelanto de sueldo sobre lo ya devengado dentro de una política.

## 2. Alcance

- Préstamos: solicitud, aprobación, desembolso, cronograma de cuotas, saldo, refinanciación, pago anticipado, cancelación.
- Adelantos: de quincena, de gratificación, extraordinarios.
- Adelanto de sueldo a pedido: sobre días devengados, con tope por política, descuento automático en la siguiente planilla y pago por transferencia o billetera.
- Descuento automático como incidencia; saldo al cese.

## 3. Fuera de alcance

- Créditos de terceros con interés comercial (solo se registra el descuento por convenio). Evaluación crediticia.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §32 Préstamos y adelantos.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-09 Adelanto de sueldo y billeteras.

## 5. Actores y permisos

- Empleado solicita. Manager o RRHH aprueba según política. Payroll desembolsa y controla.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.loans.view` | Ver préstamos | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: Manager, Empleado |
| `payroll.loans.approve` | Aprobar préstamos | Administrador empresa, RRHH, Payroll |
| `payroll.loans.cancel` | Cancelar préstamos | Administrador empresa, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `payroll.loans.create` | Crear préstamos | Administrador empresa, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `payroll.loans.disburse` | Desembolsar préstamos | Administrador empresa, Payroll |
| `payroll.loans.reject` | Rechazar préstamos | Administrador empresa, RRHH, Payroll |
| `payroll.loans.update` | Editar préstamos | Administrador empresa, Payroll |
| `payroll.salary_advances.view` | Ver adelantos de sueldo | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: Manager, Empleado |
| `payroll.salary_advances.approve` | Aprobar adelantos de sueldo | Administrador empresa, RRHH, Payroll |
| `payroll.salary_advances.cancel` | Cancelar adelantos de sueldo | Administrador empresa, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `payroll.salary_advances.create` | Crear adelantos de sueldo | Administrador empresa, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `payroll.salary_advances.pay` | Pagar adelantos de sueldo | Administrador empresa, Payroll |
| `payroll.salary_advances.reject` | Rechazar adelantos de sueldo | Administrador empresa, RRHH, Payroll |
| `payroll.advance_policies.update` **(+)** | Configurar topes, plazos y elegibilidad | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Préstamo.** 1) El colaborador solicita monto y cuotas. 2) El sistema valida elegibilidad y capacidad de descuento. 3) Aprobación. 4) Desembolso y generación del cronograma. 5) Cada periodo la cuota entra como incidencia. 6) Al completarse pasa a «Pagado».

**Adelanto de sueldo.** 1) El colaborador ve cuánto puede pedir (devengado × porcentaje − adelantos previos). 2) Solicita. 3) Si cumple la política, se aprueba automáticamente; si no, va a aprobación. 4) Se paga por el medio elegido. 5) Se descuenta íntegro en la planilla del periodo.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Préstamos | Tabla con persona, monto, cuotas, saldo, estado; nuevo, refinanciar, registrar pago | Prototipo: Nómina → Préstamos y adelantos |
| Cronograma | Cuotas con fecha, monto, estado y periodo en que se descontó | Prototipo: Nómina → Préstamos y adelantos |
| Adelantos | Solicitudes y pagos de adelanto con estado | Prototipo: Nómina → Préstamos y adelantos |
| Pedir adelanto | Monto disponible, simulación del neto resultante, solicitar | Prototipo: Nómina → Préstamos y adelantos |

## 8. Datos principales

Préstamos (`loans`), cuotas (`loan_installments`), adelantos (`salary_advances`), políticas, pagos.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PRE.

- **RN-PRE-01** (parámetro): Si el colaborador pide adelanto de sueldo → el máximo es el porcentaje de la política sobre la remuneración neta estimada devengada a la fecha, menos adelantos ya otorgados en el periodo (parámetros).
- **RN-PRE-02**: Si se otorga un adelanto → se descuenta de forma automática y completa en el cálculo del periodo al que corresponde.
- **RN-PRE-03** (parámetro): Si la suma de cuotas y descuentos voluntarios supera el porcentaje máximo del neto de la política (parámetro) → no se aprueba el préstamo con ese cronograma.
- **RN-PRE-04**: Si una cuota no puede descontarse completa (neto insuficiente, licencia sin goce) → se descuenta lo posible y el saldo se reprograma según la política, quedando registrado.
- **RN-PRE-05**: Si la relación cesa con saldo → el saldo se descuenta en la liquidación hasta el límite permitido y el remanente queda como cuenta por cobrar documentada.
- **RN-PRE-06** (parámetro): Si el colaborador no cumple la antigüedad mínima o está en periodo de prueba → no es elegible (parámetros).
- **RN-PRE-07**: Si el periodo de planilla ya está en aprobación o cerrado → los adelantos nuevos se imputan al periodo siguiente.
- **RN-PRE-08**: Si se refinancia → se cierra el cronograma anterior y se crea uno nuevo enlazado; el historial se conserva.
- **RN-PRE-09**: Si se paga por billetera o transferencia → el desembolso sale por adaptador con idempotencia y solo se marca «Pagado» con confirmación.

## 10. Estados

- ME-21 · Préstamo (`05-maquinas-de-estado.md`): estados Solicitado, Aprobado, Desembolsado, En pago, Pagado, Refinanciado, Rechazado, Cancelado.
- ME-22 · Adelanto de sueldo (`05-maquinas-de-estado.md`): estados Solicitado, Aprobado, Pagado, Descontado, Rechazado.

## 11. Eventos

**Emite:**

- `loan.requested`, `loan.approved`, `loan.disbursed`, `loan.installment_due`, `loan.installment_paid`, `loan.paid_off`, `advance.requested`, `advance.paid`, `advance.deducted`.

**Consume:**

- `payroll.closed`, `employee.terminated`, `approval.approved`.

## 12. Integraciones

Bancos y billeteras digitales por adaptador (M-INT).

## 13. Casos borde

- Varios adelantos en el mismo periodo.
- Cese entre el adelanto y el cierre.
- Préstamo en moneda distinta de la remuneración.

## 14. Criterios de aceptación generales

- Cada préstamo muestra cuotas, saldo y cronograma, y sus cuotas se descuentan solas en planilla.
