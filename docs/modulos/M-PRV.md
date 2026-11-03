# M-PRV · Provisiones y asientos contables

Fase 3 · Nómina Perú · Épica `PRV` · Reglas `RN-PRV-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Reflejar el costo laboral en contabilidad: provisiones mensuales de beneficios y asiento de planilla por centro de costo, exportable al ERP.

## 2. Alcance

- Provisión mensual de CTS, gratificaciones, vacaciones y otros pasivos laborales, por relación.
- Asiento de planilla, de provisiones, de pagos y de liquidaciones; plan de cuentas de nómina y reglas de contabilización por concepto, tipo de trabajador y centro de costo.
- Distribución por centro de costo fijo, porcentual o según hojas de tiempo.
- Exportación en formato configurable y por API; reversas y ajustes; conciliación provisión frente a pago.

## 3. Fuera de alcance

- Contabilidad general. Plan contable completo de la empresa.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-03 Provisiones y centralización contable.

## 5. Actores y permisos

- Payroll genera. Contador revisa, exporta y concilia.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.accounting_entries.view` | Ver asientos contables | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.accounting_entries.create` | Crear asientos contables | Administrador empresa, Payroll |
| `payroll.accounting_entries.export` | Exportar asientos contables | Administrador empresa, Payroll, Contador |
| `payroll.accounting_entries.post` | Contabilizar asientos contables | Administrador empresa, Payroll |
| `payroll.provisions.view` | Ver provisiones | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.accounting_accounts.update` **(+)** | Configurar cuentas y reglas de contabilización | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Asiento del periodo.** 1) Al cerrar el periodo, el sistema aplica las reglas de contabilización. 2) Genera el asiento por centro de costo con debe y haber cuadrados. 3) El contador lo revisa. 4) Se exporta o se envía por API. 5) Se marca «Contabilizado» con la referencia del ERP.

**Provisión.** 1) Cada mes se calcula lo devengado de cada beneficio por relación. 2) Se registra la provisión y su asiento. 3) Al pagar el beneficio, se aplica contra la provisión y se muestra la diferencia.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Asientos | Por periodo y tipo: estado, total, cuadre; ver detalle, exportar | Prototipo: Nómina → Declaraciones (asiento de centralización) |
| Detalle del asiento | Líneas por cuenta, centro de costo, debe, haber; origen por concepto | Prototipo: Nómina → Declaraciones (asiento de centralización) |
| Provisiones | Saldos por beneficio y relación; movimiento del mes; conciliación | Prototipo: Nómina → Beneficios sociales → pestaña Provisiones |
| Cuentas y reglas | Mapeo concepto → cuentas por tipo de trabajador y centro de costo | Prototipo: Nómina → Configuración de nómina → pestaña Conceptos (cuentas por concepto) |

## 8. Datos principales

Reglas de contabilización, cuentas, asientos (`journal_entries`) y líneas, provisiones por relación y mes, exportaciones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PRV.

- **RN-PRV-01**: Si se genera un asiento → el total del debe es igual al del haber; si no cuadra, no se puede exportar y se indica el concepto sin regla.
- **RN-PRV-02**: Si un concepto con importe no tiene regla de contabilización → el asiento queda «incompleto» y lo señala.
- **RN-PRV-03** (parámetro): Si se calcula la provisión mensual → usa las mismas fórmulas de devengo del beneficio (gratificación, CTS, vacaciones) según régimen, sobre la remuneración computable del mes (parámetros del paquete de país).
- **RN-PRV-04**: Si el beneficio se paga → la provisión acumulada se revierte contra el pago y la diferencia se registra como ajuste.
- **RN-PRV-05**: Si una relación reparte su costo entre varios centros → las líneas se dividen con los porcentajes vigentes (o los de hojas de tiempo aprobadas).
- **RN-PRV-06**: Si un asiento ya fue exportado → no se modifica; una corrección genera un asiento de ajuste o reversa.
- **RN-PRV-07**: Si el periodo se reabre → el asiento no exportado se anula; si ya se exportó, se exige reversa.
- **RN-PRV-08**: Si la exportación se envía por API → usa idempotencia y registra la referencia devuelta por el ERP.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `accounting.entry_generated`, `accounting.entry_exported`, `accounting.provision_posted`.

**Consume:**

- `payroll.closed`, `payroll.reopened`, `benefit.cts_deposited`, `settlement.approved`, `timesheet.approved`.

## 12. Integraciones

ERP y sistemas contables por archivo configurable o API (M-INT).

## 13. Casos borde

- Trabajador que cambia de centro de costo a mitad de mes.
- Provisión de vacaciones con cambio de sueldo.
- Empresa con moneda funcional distinta.

## 14. Criterios de aceptación generales

- El asiento por centro de costo se genera, cuadra y se exporta; las provisiones concilian contra los pagos.
