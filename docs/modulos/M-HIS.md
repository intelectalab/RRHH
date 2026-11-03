# M-HIS · Historia con vigencia

Fase 1 · Core HR · Épica `HIS` · Reglas `RN-HIS-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Guardar con fecha de inicio y fin todo lo que cambia en una relación laboral, para no perder la historia, poder programar cambios futuros y recalcular planilla hacia atrás.

## 2. Alcance

- Dimensiones con vigencia: asignación (posición, puesto, unidad, sede, centro de costo), jefe, compensación, contrato, horario, estado, régimen laboral y pensionario.
- Movimientos tipificados: ingreso, promoción, traslado, cambio de jefe, incremento, cambio de jornada, cambio de sede, cambio de régimen, suspensión, cese.
- Cambios futuros programados y correcciones retroactivas controladas.
- Línea de tiempo y consulta «a la fecha».
- Movimientos masivos.

## 3. Fuera de alcance

- La auditoría de quién registró (M-AUD). El contenido del contrato (M-CON).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §7 Historial laboral; §104 Integridad referencial; §116 Una sola fuente de verdad.

**Correcciones que incorpora:** C-03 Historia con vigencia; C-11 Una sola fuente de verdad.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH registra movimientos. Payroll registra cambios de compensación. Manager propone (vía aprobación). Empleado consulta su historia.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `people.assignments.view` | Ver movimientos de puesto, unidad, sede, jefe y horario | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `people.assignments.cancel` | Cancelar movimientos de puesto, unidad, sede, jefe y horario | Administrador empresa, RRHH |
| `people.assignments.create` | Crear movimientos de puesto, unidad, sede, jefe y horario | Administrador empresa, RRHH |
| `people.assignments.correct` **(+)** | Corregir o anular un movimiento ya vigente | Administrador empresa, RRHH |
| `people.assignments.bulk_create` **(+)** | Registrar movimientos en bloque | Administrador empresa, RRHH |
| `people.assignments.propose` **(+)** | Proponer un movimiento para su equipo | Manager |
| `people.compensations.view` **(S)** | Ver registros de compensación con vigencia | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `people.compensations.cancel` **(S)** | Cancelar registros de compensación con vigencia | Administrador empresa, Payroll |
| `people.compensations.create` **(S)** | Crear registros de compensación con vigencia | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Registrar un movimiento.** 1) Se elige tipo, fecha de vigencia y nuevos valores. 2) El sistema cierra el registro vigente el día anterior y crea el nuevo. 3) Si la fecha es futura, queda «programado» y se aplica ese día. 4) Emite el evento de la dimensión afectada.

**Corrección retroactiva.** 1) Se elige el registro erróneo. 2) Se indica el valor correcto y el motivo. 3) El sistema crea el registro corregido, marca el anterior como «reemplazado» (no lo borra). 4) Si afecta periodos de planilla cerrados, genera diferencias para el periodo abierto.

**Consulta a la fecha.** 1) Se elige una fecha. 2) La ficha, el organigrama y los reportes muestran los valores vigentes ese día.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Línea de tiempo | Movimientos ordenados con antes y después, tipo, vigencia y autor; filtro por dimensión | Prototipo: Personas → Colaboradores → Ficha → pestaña Historial |
| Registrar movimiento | Tipo, fecha de vigencia, nuevos valores, motivo; aviso de impacto | Prototipo: Personas → Colaboradores → Ficha → Registrar movimiento |
| Movimientos programados | Cambios futuros pendientes de aplicarse; editar o cancelar | Prototipo: Personas → Movimientos programados |
| Movimientos masivos | Selección de personas y cambio común con fecha; vista previa | Prototipo: Personas → Colaboradores → selección y acciones masivas |

## 8. Datos principales

Asignaciones (`employment_assignments`), compensaciones (`employment_compensations`), líneas de reporte, estados de la relación, tipos de movimiento; todas con `valid_from`, `valid_to`, motivo y referencia al registro reemplazado.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección HIS.

- **RN-HIS-01**: Si se registra un cambio en una dimensión con vigencia → se inserta un registro nuevo y se cierra el anterior el día previo; nunca se actualiza el valor en sitio.
- **RN-HIS-02**: Si dos registros de la misma dimensión y relación se solaparan en fechas → la base lo impide.
- **RN-HIS-03**: Si entre dos registros de una dimensión obligatoria quedara un hueco de fechas → se rechaza.
- **RN-HIS-04**: Si la fecha de vigencia es futura → el cambio no afecta permisos, organigrama ni planilla hasta ese día.
- **RN-HIS-05**: Si la fecha de vigencia cae en un periodo de planilla cerrado → el cambio se acepta solo con permiso de corrección y genera una diferencia retroactiva en el periodo abierto; el periodo cerrado no se altera.
- **RN-HIS-06**: Si se corrige un movimiento → el registro original queda marcado como reemplazado y visible en la línea de tiempo con el motivo.
- **RN-HIS-07**: Si un movimiento cambia puesto, categoría o remuneración → se genera la evidencia de comunicación de la política salarial (Ley 30709) para firma o acuse.
- **RN-HIS-08**: Si un incremento salarial proviene de un ciclo de revisión o de una promoción → queda enlazado a su origen y a su aprobación.
- **RN-HIS-09**: Si se pide un dato «a la fecha X» → se devuelve el registro cuya vigencia contiene X.

## 10. Estados

- ME-01 · Relación laboral (`05-maquinas-de-estado.md`): estados Por ingresar, Activa, Suspendida, En proceso de cese, Cesada, Anulada.

## 11. Eventos

**Emite:**

- `employment.assignment_changed`, `employment.manager_changed`, `employment.compensation_changed`, `employment.schedule_changed`, `employment.status_changed`, `employment.regime_changed`, `employee.promoted`, `employee.transferred`.

**Consume:**

- `compensation_cycle.applied`, `approval.approved` (propuestas de manager), `contract.signed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Dos cambios el mismo día en la misma dimensión: gana el último registrado; el anterior queda reemplazado.
- Cancelar un cambio futuro ya comunicado.
- Reorganización masiva con fecha futura y bajas intermedias.
- Cambio de régimen pensionario a mitad de mes.

## 14. Criterios de aceptación generales

- La línea de tiempo muestra cargo, área, sede, jefe, salario, contrato, horario, centro de costo y estado con sus fechas.
- Un cambio con fecha futura se aplica solo, y uno retroactivo produce diferencias en planilla sin alterar periodos cerrados.
