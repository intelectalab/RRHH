# M-GAS · Gastos, viáticos y reembolsos

Fase 7 · Operación y cumplimiento · Épica `GAS` · Reglas `RN-GAS-nn` · Módulo comercial: Gastos y viáticos (`expenses`)

## 1. Objetivo

Controlar los gastos del personal de principio a fin, con políticas por puesto, anticipos de viáticos y rendición.

## 2. Alcance

- Registro de gasto con comprobante (foto), categoría, monto, moneda, centro de costo, proyecto.
- Informes de gastos; flujo empleado → manager → finanzas → aprobado → pagado.
- Políticas por puesto, nivel y destino: topes por categoría, por día y por viaje; documentos exigidos.
- Viáticos: solicitud de anticipo, entrega, rendición, devolución o reembolso del saldo.
- Pago por planilla o por tesorería; contabilización.
- Lectura de comprobantes por OCR como ayuda; detección de duplicados.

## 3. Fuera de alcance

- Reserva de viajes. Tarjetas corporativas (integración futura).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §36 Gastos y reembolsos.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-23 Viáticos y rendiciones.

## 5. Actores y permisos

- Empleado registra y rinde. Manager aprueba. Finanzas revisa y paga. Auditor consulta.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `expenses.reports.view` | Ver informes de gastos | Administrador empresa, Payroll, Manager, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `expenses.reports.approve` | Aprobar informes de gastos | Administrador empresa, Manager |
| `expenses.reports.create` | Crear informes de gastos | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.reports.mark_paid` | Marcar como pagado informes de gastos | Administrador empresa, Payroll |
| `expenses.reports.reject` | Rechazar informes de gastos | Administrador empresa, Payroll, Manager, Contador |
| `expenses.reports.submit` | Enviar informes de gastos | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.reports.update` | Editar informes de gastos | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.reports.review` **(+)** | Revisión de finanzas y observaciones | Administrador empresa, Payroll, Contador |
| `expenses.advances.view` | Ver anticipos de viáticos y rendiciones | Administrador empresa, Payroll, Manager, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `expenses.advances.approve` | Aprobar anticipos de viáticos y rendiciones | Administrador empresa, Manager |
| `expenses.advances.create` | Crear anticipos de viáticos y rendiciones | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.advances.mark_paid` | Marcar como pagado anticipos de viáticos y rendiciones | Administrador empresa, Payroll |
| `expenses.advances.reject` | Rechazar anticipos de viáticos y rendiciones | Administrador empresa, Manager |
| `expenses.advances.settle` | Rendir anticipos de viáticos y rendiciones | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.policies.view` | Ver categorías, topes y políticas de gasto | Administrador empresa, Payroll, Auditor, Contador |
| `expenses.policies.archive` | Archivar categorías, topes y políticas de gasto | Administrador empresa |
| `expenses.policies.create` | Crear categorías, topes y políticas de gasto | Administrador empresa |
| `expenses.policies.update` | Editar categorías, topes y políticas de gasto | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Reembolso.** 1) El colaborador registra gastos con comprobante. 2) Los agrupa en un informe y lo envía. 3) El sistema marca lo que excede la política. 4) El manager aprueba. 5) Finanzas revisa comprobantes y aprueba u observa. 6) Se paga por planilla (concepto no remunerativo) o por tesorería. 7) Se contabiliza.

**Viático.** 1) Se solicita anticipo por viaje (destino, días, motivo). 2) Aprobación y entrega. 3) Al volver, se rinde con comprobantes. 4) Si gastó menos, devuelve; si gastó más y está aprobado, se reembolsa. 5) Rendición cerrada.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Gastos | Informes por estado, persona, monto; filtros | Prototipo: Operaciones → Gastos |
| Mis gastos | Gastos sueltos, informes, anticipos y rendiciones; registrar con foto | Prototipo: Operaciones → Gastos |
| Revisión de finanzas | Informe con comprobantes, excesos de política, observar o aprobar | Prototipo: Operaciones → Gastos |
| Viáticos | Anticipos entregados, pendientes de rendir, vencidos | Prototipo: Operaciones → Gastos |
| Políticas de gasto | Categorías, topes por puesto y destino | Prototipo: Operaciones → Gastos |

## 8. Datos principales

Gastos (`expense_items`), informes (`expense_reports`), comprobantes, categorías, políticas y topes, anticipos, rendiciones, pagos.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección GAS.

- **RN-GAS-01**: Si un gasto no tiene comprobante y su categoría lo exige → no puede incluirse en un informe.
- **RN-GAS-02** (parámetro): Si un gasto supera el tope de su categoría para el puesto o destino (parámetro de política) → se marca como exceso y requiere justificación y aprobación adicional, o se reembolsa solo hasta el tope según la política.
- **RN-GAS-03**: Si el mismo comprobante (emisor, número, monto) ya fue registrado → se advierte como posible duplicado y finanzas debe resolver.
- **RN-GAS-04** (parámetro): Si un gasto se registra pasado el plazo máximo desde su fecha (parámetro) → requiere excepción.
- **RN-GAS-05** (parámetro): Si un anticipo no se rinde dentro del plazo (parámetro) → se alerta y, según política, se bloquean nuevos anticipos.
- **RN-GAS-06**: Si la rendición es menor que el anticipo → se registra la devolución o el descuento autorizado por el trabajador.
- **RN-GAS-07**: Si el aprobador es quien registró el gasto → sube al siguiente nivel.
- **RN-GAS-08**: Si el gasto está en otra moneda → se convierte con el tipo de cambio de la fecha del gasto.
- **RN-GAS-09**: Si el pago va por planilla → entra como concepto no remunerativo del periodo abierto; si va por tesorería → en lote de pago propio.
- **RN-GAS-10**: Si el informe está «Pagado» → no se modifica.

## 10. Estados

- ME-32 · Informe de gastos (`05-maquinas-de-estado.md`): estados Borrador, Enviado (manager), En revisión de finanzas, Observado, Aprobado, Pagado, Rechazado.
- ME-33 · Anticipo de viático y rendición (`05-maquinas-de-estado.md`): estados Solicitado, Aprobado, Entregado, Por rendir, Rendido, Vencido sin rendir, Cerrado, Rechazado.

## 11. Eventos

**Emite:**

- `expense.submitted`, `expense.approved`, `expense.rejected`, `expense.approved_for_payroll`, `expense.paid`, `travel_advance.granted`, `travel_advance.overdue`, `travel_advance.settled`.

**Consume:**

- `approval.approved`, `payroll.closed`, `employee.terminated`.

## 12. Integraciones

OCR, validación de comprobantes electrónicos, ERP (M-INT).

## 13. Casos borde

- Gasto compartido entre varias personas.
- Cese con anticipo sin rendir: pasa a liquidación.
- Comprobante a nombre de la persona y no de la empresa.

## 14. Criterios de aceptación generales

- El flujo empleado → manager → finanzas → aprobado → pagado funciona, con políticas por puesto y rendición de viáticos.
