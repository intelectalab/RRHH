# M-LOC · Locadores y cuarta categoría

Fase 3 · Nómina Perú · Épica `LOC` · Reglas `RN-LOC-nn` · Módulo comercial: Locadores (`contractors`)

## 1. Objetivo

Gestionar a los prestadores de servicios independientes separados de la planilla: contratos de locación, recibos por honorarios, retenciones y pagos.

## 2. Alcance

- Ficha del prestador (persona con relación de tipo «locador»), contrato de servicios, entregables y vigencia.
- Recibos por honorarios: registro, validación, retención del impuesto, suspensión de retenciones, pago.
- Reporte para declaración de cuarta categoría y archivo de pago.
- Alertas de riesgo de laboralidad (indicadores configurables).

## 3. Fuera de alcance

- Beneficios laborales, asistencia obligatoria, vacaciones (no aplican). Emisión del recibo electrónico (lo emite el prestador).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-18 Locadores / cuarta categoría.

## 5. Actores y permisos

- RRHH o compras registra al prestador. Responsable del servicio valida entregables. Payroll o tesorería paga.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.contractors.view` | Ver locadores y contratos de servicios | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.contractors.create` | Crear locadores y contratos de servicios | Administrador empresa, RRHH |
| `payroll.contractors.end` | Finalizar locadores y contratos de servicios | Administrador empresa, RRHH |
| `payroll.contractors.update` | Editar locadores y contratos de servicios | Administrador empresa, RRHH |
| `payroll.contractor_invoices.view` | Ver recibos por honorarios | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: Manager |
| `payroll.contractor_invoices.approve` | Aprobar recibos por honorarios | Administrador empresa; solo sobre lo propio o asignado: Manager |
| `payroll.contractor_invoices.create` | Crear recibos por honorarios | Administrador empresa, Payroll |
| `payroll.contractor_invoices.mark_paid` | Marcar como pagado recibos por honorarios | Administrador empresa, Payroll |
| `payroll.contractor_invoices.reject` | Rechazar recibos por honorarios | Administrador empresa; solo sobre lo propio o asignado: Manager |
| `payroll.contractor_invoices.update` | Editar recibos por honorarios | Administrador empresa, Payroll |
| `payroll.contractors.fees.view` **(S)** **(+)** | Ver honorarios pactados y pagados | Administrador empresa, Payroll, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ciclo mensual.** 1) El prestador o el responsable carga el recibo. 2) El responsable da conformidad al servicio. 3) Payroll valida datos y calcula la retención. 4) Se incluye en el lote de pago. 5) Queda en el reporte de cuarta categoría del periodo.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Locadores | Prestadores con contrato, vigencia, responsable, monto pactado (con permiso) | Prototipo: Nómina → Locadores |
| Recibos por honorarios | Recibos por periodo con estado, retención y pago; registrar, validar | Prototipo: Nómina → Locadores |

## 8. Datos principales

Prestadores (relación de tipo locador), contratos de servicios, recibos (`contractor_invoices`), retenciones, constancias de suspensión.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección LOC.

- **RN-LOC-01**: Si la relación es de tipo locador → no entra a ninguna planilla ni genera beneficios sociales, asistencia obligatoria ni vacaciones.
- **RN-LOC-02** (parámetro): Si el recibo supera el monto mínimo sujeto a retención y el prestador no tiene suspensión vigente → se retiene el porcentaje del impuesto (parámetros del paquete de país, a confirmar con la norma vigente).
- **RN-LOC-03**: Si el recibo no tiene conformidad del responsable del servicio → no entra al lote de pago.
- **RN-LOC-04**: Si un mismo número de recibo del mismo prestador se registra dos veces → se rechaza.
- **RN-LOC-05** (parámetro): Si un prestador acumula indicadores de laboralidad (horario fijo, exclusividad, continuidad mayor al umbral; parámetros) → se alerta a RRHH con explicación; la decisión es humana.
- **RN-LOC-06**: Si el contrato de servicios venció → no se aceptan recibos por periodos posteriores sin renovación.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `contractor.created`, `fee_receipt.registered`, `fee_receipt.approved`, `fee_receipt.paid`.

**Consume:**

- `bank_batch.reconciled`.

## 12. Integraciones

Bancos; validación del comprobante ante la administración tributaria por adaptador opcional.

## 13. Casos borde

- Locador que pasa a planilla: nueva relación laboral, misma persona.
- Recibo en moneda extranjera.

## 14. Criterios de aceptación generales

- Los locadores se gestionan sin mezclarse con trabajadores en planilla, tableros de dotación ni declaraciones laborales.
