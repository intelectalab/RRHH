# M-CON · Contratos y adendas

Fase 1 · Core HR · Épica `CON` · Reglas `RN-CON-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Administrar los contratos de trabajo, sus renovaciones y adendas, con alertas de vencimiento y de tope acumulado para evitar la desnaturalización.

## 2. Alcance

- Tipos de contrato por país (indeterminado, modalidades sujetas a plazo, tiempo parcial, convenios formativos) con plazo máximo y causa objetiva.
- Contrato con inicio, fin, periodo de prueba, jornada, remuneración pactada, causa objetiva, documento.
- Renovaciones y prórrogas, adendas (cambio de remuneración, puesto, jornada, teletrabajo), terminación.
- Alertas de vencimiento y de tope acumulado; renovación masiva con generación y firma.
- Acuerdo de teletrabajo con contenido mínimo.

## 3. Fuera de alcance

- Plantillas y firma (M-DOC). Convenios de locación de servicios (M-LOC).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §18 Contratos.

**Correcciones que incorpora:** C-03 Historia con vigencia.

**Ampliaciones que incorpora:** E-08 Contratos a modalidad; E-20 Teletrabajo (Ley 31572).

## 5. Actores y permisos

- RRHH redacta y renueva. Asesor legal o administrador aprueba. Colaborador firma. Manager recibe alertas de su equipo.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `contracts.contracts.view` | Ver contratos | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `contracts.contracts.create` | Crear contratos | Administrador empresa, RRHH |
| `contracts.contracts.issue` | Emitir contratos | Administrador empresa, RRHH |
| `contracts.contracts.renew` | Renovar contratos | Administrador empresa, RRHH |
| `contracts.contracts.terminate` | Terminar o cesar contratos | Administrador empresa, RRHH |
| `contracts.contracts.update` | Editar contratos | Administrador empresa, RRHH |
| `contracts.contracts.approve` **(+)** | Aprobar contratos y renovaciones antes de la firma | Administrador empresa, RRHH |
| `contracts.contracts.bulk_renew` **(+)** | Renovar contratos en bloque | Administrador empresa, RRHH |
| `contracts.addenda.view` | Ver adendas | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `contracts.addenda.cancel` | Cancelar adendas | Administrador empresa, RRHH |
| `contracts.addenda.create` | Crear adendas | Administrador empresa, RRHH |
| `contracts.addenda.issue` | Emitir adendas | Administrador empresa, RRHH |
| `contracts.contract_types.view` | Ver tipos de contrato y sus topes | Administrador empresa, RRHH, Payroll, Auditor |
| `contracts.contract_types.create` | Crear tipos de contrato propios de la organización | Administrador empresa |
| `contracts.contract_types.update` | Administrar tipos de contrato y sus topes | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Contrato inicial.** 1) Al registrar el ingreso se elige tipo, fechas, periodo de prueba, jornada y causa objetiva si es a plazo. 2) Se genera el documento desde plantilla. 3) Se aprueba y se envía a firma. 4) Firmado por ambas partes pasa a «Vigente» en su fecha de inicio.

**Renovación.** 1) El sistema alerta con la anticipación configurada. 2) RRHH decide renovar, convertir a indeterminado o no renovar. 3) Al renovar se valida el tope de la modalidad y el tope total. 4) Se genera la prórroga y se firma.

**Renovación masiva.** 1) Se filtran contratos por vencer. 2) Se elige nueva fecha fin común o por regla. 3) El sistema excluye y explica los que superarían un tope. 4) Genera documentos y lanza las firmas.

**Adenda.** 1) Se registra el cambio pactado con fecha. 2) Se genera y firma la adenda. 3) Firmada, crea el movimiento con vigencia en M-HIS.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Contratos | Tabla con tipo, inicio, fin, estado, días para vencer, acumulado frente al tope; filtros y renovación masiva | Prototipo: Operaciones → Contratos |
| Por vencer | Contratos que vencen en la ventana elegida con acción sugerida | Prototipo: Operaciones → Contratos |
| Contrato del colaborador | Contrato vigente, historial de renovaciones y adendas, periodo de prueba, documento | Prototipo: Personas → Colaboradores → Ficha → pestaña Contrato |
| Tipos de contrato | Catálogo con plazo máximo, causa objetiva, plantilla asociada | Prototipo: Configuración → Centro de configuración → sección Configuración de cada módulo |

## 8. Datos principales

Contratos (`contracts`, con vigencia), renovaciones, adendas, tipos de contrato y sus topes (parámetros del paquete de país), acuerdos de teletrabajo.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CON.

- **RN-CON-01**: Si el contrato es a plazo fijo (modalidad) → exige fecha de fin y causa objetiva escrita.
- **RN-CON-02** (parámetro): Si la suma de plazos de una misma modalidad para la persona en la empresa supera el máximo de esa modalidad (parámetro del paquete de país) → no se permite renovar bajo esa modalidad.
- **RN-CON-03** (parámetro): Si la suma de todos los contratos a modalidad supera el tope total (parámetro del paquete de país, a confirmar con la norma vigente) → se bloquea la renovación y se propone convertir a indeterminado.
- **RN-CON-04** (parámetro): Si faltan N días para el vencimiento (parámetro, varias anticipaciones) → se genera alerta a RRHH y al jefe con explicación.
- **RN-CON-05**: Si un contrato a plazo vence sin renovación ni cese registrado y la persona sigue activa → se marca «vencido sin regularizar», se alerta como riesgo de desnaturalización y aparece en cumplimiento.
- **RN-CON-06**: Si un contrato no está firmado por ambas partes → no pasa a «Vigente» (salvo carga de contrato físico escaneado con marca de origen).
- **RN-CON-07** (parámetro): Si el periodo de prueba supera el máximo del tipo de puesto (parámetro del paquete de país, a confirmar) → se rechaza.
- **RN-CON-08**: Si una adenda firmada cambia remuneración, puesto o jornada → se registra el movimiento con vigencia correspondiente de forma automática.
- **RN-CON-09**: Si el contrato es de tiempo parcial o modalidad formativa → el régimen de beneficios de la relación se ajusta según el paquete de país.
- **RN-CON-10**: Si se pacta teletrabajo → el acuerdo debe contener todos los campos mínimos (modalidad, jornada, desconexión, lugar, supervisión, equipos, compensación) antes de enviarse a firma.

## 10. Estados

- ME-11 · Contrato (`05-maquinas-de-estado.md`): estados Borrador, En aprobación, En firma, Firmado, Vigente, Por vencer, Vencido sin regularizar, Terminado, Anulado.

## 11. Eventos

**Emite:**

- `contract.created`, `contract.issued`, `contract.signed`, `contract.renewed`, `contract.amended`, `contract.expiring`, `contract.expired`, `contract.terminated`.

**Consume:**

- `employee.hired`, `employee.terminated`, `signature.completed`, `signature.declined`.

## 12. Integraciones

Firma electrónica y digital (M-DOC). T-Registro recibe tipo de contrato (M-DEC).

## 13. Casos borde

- Reingreso: los plazos anteriores cuentan para el tope según la regla del paquete de país.
- Renovación firmada después de la fecha de vencimiento.
- Conversión a indeterminado a mitad de contrato.
- Contrato suspendido por licencia: el plazo sigue corriendo salvo regla distinta.

## 14. Criterios de aceptación generales

- Se listan los contratos próximos a vencer y los que alcanzan topes, con explicación.
- Una renovación masiva genera documentos, lanza firmas y actualiza vigencias al completarse.
