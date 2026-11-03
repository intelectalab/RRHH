# M-ACT · Activos, EPP y uniformes

Fase 7 · Operación y cumplimiento · Épica `ACT` · Reglas `RN-ACT-nn` · Módulo comercial: Activos y EPP (`assets`)

## 1. Objetivo

Saber qué tiene cada persona, en qué estado y cuándo debe devolverse o reponerse, incluidos los equipos de protección y uniformes.

## 2. Alcance

- Inventario: laptop, celular, monitor, uniforme, tarjeta, equipo, licencia de software, otros; categorías, serie, valor, proveedor, garantía.
- Estados disponible, asignado, mantenimiento, perdido, baja; historial completo.
- Asignación con acta firmada, devolución con verificación de estado.
- EPP y uniformes: tallas por persona, entregas firmadas, vida útil, vencimientos y reposición; kit por puesto.
- Solicitudes de activo; mantenimiento; licencias con cupos.

## 3. Fuera de alcance

- Compras. Gestión remota de dispositivos (integración).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §37 Activos.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-12 EPP y uniformes.

## 5. Actores y permisos

- Administración, TI o SST administran. Colaborador recibe, firma y devuelve. Manager solicita para su equipo.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `assets.assets.view` | Ver activos | Administrador empresa, RRHH, Manager, Auditor, SST |
| `assets.assets.assign` | Asignar activos | Administrador empresa, RRHH, SST |
| `assets.assets.create` | Crear activos | Administrador empresa, RRHH, SST |
| `assets.assets.report_lost` | Marcar como perdidos activos | Administrador empresa, RRHH, SST |
| `assets.assets.retire` | Retirar activos | Administrador empresa, RRHH, SST |
| `assets.assets.return` | Registrar la devolución de activos | Administrador empresa, RRHH, SST |
| `assets.assets.send_to_maintenance` | Enviar a mantenimiento activos | Administrador empresa, RRHH, SST |
| `assets.assets.update` | Editar activos | Administrador empresa, RRHH, SST |
| `assets.assets.request` **(+)** | Solicitar activos para sí o para su equipo | Manager, Empleado |
| `assets.ppe_deliveries.view` | Ver entregas de EPP y uniformes | Administrador empresa, RRHH, Auditor, SST; solo sobre lo propio o asignado: Empleado |
| `assets.ppe_deliveries.create` | Crear entregas de EPP y uniformes | Administrador empresa, SST |
| `assets.ppe_deliveries.sign` | Firmar entregas de EPP y uniformes | solo sobre lo propio o asignado: todos los roles de organización |
| `assets.assets.value.view` **(S)** **(+)** | Ver valor y costo de los activos | Administrador empresa, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Asignación.** 1) Se elige un activo disponible y la persona. 2) Se genera el acta de entrega. 3) La persona firma. 4) El activo pasa a «Asignado». **Devolución.** 1) Se registra el estado al recibir. 2) Se firma el acta de devolución. 3) Vuelve a «Disponible» o va a «Mantenimiento» o «Baja».

**Entrega de EPP.** 1) Según el kit del puesto y las tallas. 2) Se entrega con firma. 3) Se agenda la reposición por vida útil. 4) Alimenta el registro SST.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Activos | Inventario con categoría, estado, asignado a, ubicación; crear, asignar | Prototipo: Operaciones → Activos |
| Detalle del activo | Datos, historial de asignaciones, mantenimientos, documentos | Prototipo: Operaciones → Activos |
| EPP y uniformes | Kits por puesto, tallas, entregas, vencimientos | Prototipo: Operaciones → Activos |
| Activos del colaborador | Lo asignado y su historial | Prototipo: Personas → Colaboradores → Ficha → pestaña Activos |

## 8. Datos principales

Activos (`assets`), categorías, asignaciones, actas, mantenimientos, licencias, kits de EPP, tallas por persona, entregas de EPP.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ACT.

- **RN-ACT-01**: Si un activo no está «Disponible» → no puede asignarse.
- **RN-ACT-02**: Si un activo unitario está asignado → no admite una segunda asignación simultánea.
- **RN-ACT-03**: Si se asigna un activo → requiere acta firmada por quien recibe para quedar «Asignado».
- **RN-ACT-04**: Si la relación cesa con activos asignados → se crean las tareas de devolución en offboarding y la liquidación recibe la lista de pendientes.
- **RN-ACT-05**: Si un activo se da de baja o se marca perdido → exige motivo y conserva su historial.
- **RN-ACT-06** (parámetro): Si un EPP alcanza su vida útil o fecha de vencimiento (parámetro por tipo) → se genera la tarea de reposición antes de vencer.
- **RN-ACT-07**: Si el puesto tiene kit de EPP → el ingreso o cambio a ese puesto crea la tarea de entrega.
- **RN-ACT-08** (parámetro): Si las licencias asignadas igualan los cupos del software → no se asigna otra sin ampliar.

## 10. Estados

- ME-34 · Activo (`05-maquinas-de-estado.md`): estados Disponible, Asignado, Mantenimiento, Perdido, Baja.

## 11. Eventos

**Emite:**

- `asset.created`, `asset.assigned`, `asset.returned`, `asset.sent_to_maintenance`, `asset.lost`, `asset.retired`, `ppe.delivered`, `ppe.expiring`.

**Consume:**

- `employee.hired`, `employee.terminated`, `employment.assignment_changed`, `onboarding.started`.

## 12. Integraciones

Gestión de dispositivos y de licencias por adaptador (M-INT).

## 13. Casos borde

- Activo compartido por un equipo.
- Devolución con daño: registro y decisión de cobro según política.
- Cambio de talla.

## 14. Criterios de aceptación generales

- Los activos recorren los cinco estados con historial completo y las entregas de EPP quedan firmadas.
