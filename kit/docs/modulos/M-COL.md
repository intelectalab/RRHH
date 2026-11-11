# M-COL · Colaboradores y ficha 360

Fase 1 · Core HR · Épica `COL` · Reglas `RN-COL-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Ser la fuente única de información del colaborador: quién es la persona, qué relaciones laborales tiene y todo lo que los demás módulos saben de ella, en una sola ficha.

## 2. Alcance

- Persona (`people`): identidad, documento, nacimiento, sexo cuando la norma lo requiere, nacionalidad, estado civil, dirección, teléfonos, correo opcional, foto, contacto de emergencia.
- Relación laboral (`employments`): empresa, código, fecha de ingreso, tipo de trabajador, régimen laboral, modalidad, estado.
- Derechohabientes (cónyuge, conviviente, hijos) con sustento; datos de pago (banco, cuenta, CCI, billetera); régimen pensionario y de salud; seguros.
- Ficha con pestañas: Resumen, Información, Contrato, Compensación, Asistencia, Vacaciones, Planilla, Documentos, Activos, Desempeño, Objetivos, Competencias, Capacitaciones, Beneficios, Encuestas, Reconocimientos, Historial, Actividad. Cada pestaña aparece solo si el módulo está contratado y el usuario tiene permiso.
- Alta, reingreso, relaciones simultáneas en empresas del grupo, cese.
- Solicitudes de cambio de datos por el propio colaborador con aprobación.

## 3. Fuera de alcance

- Movimientos con vigencia (M-HIS), contrato (M-CON), liquidación (M-LIQ).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §6 Ficha 360° del colaborador; §102 Arquitectura de datos; §104 Integridad referencial; §116 Una sola fuente de verdad.

**Correcciones que incorpora:** C-01 Persona ≠ usuario ≠ colaborador ≠ relación laboral; C-11 Una sola fuente de verdad.

**Ampliaciones que incorpora:** E-19 Derechohabientes, seguros y regímenes.

## 5. Actores y permisos

- RRHH registra y edita. Payroll ve y edita compensación y datos de pago. Manager ve a su equipo sin campos sensibles. Empleado ve y propone cambios de lo propio.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `people.employments.view` | Ver colaboradores (relaciones laborales) | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador; solo sobre lo propio o asignado: Empleado |
| `people.employments.activate` | Activar colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.cancel_termination` | Revertir el cese de colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.create` | Crear colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.suspend` | Suspender colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.terminate` | Terminar o cesar colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.update` | Editar colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.people.view` | Ver personas | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador; solo sobre lo propio o asignado: Empleado |
| `people.people.create` | Crear personas | Administrador empresa, RRHH |
| `people.people.merge` | Fusionar personas | Administrador empresa |
| `people.people.update` | Editar personas | Administrador empresa, RRHH |
| `people.dependents.view` | Ver derechohabientes | Administrador empresa, RRHH, Payroll, Auditor; solo sobre lo propio o asignado: Empleado |
| `people.dependents.create` | Crear derechohabientes | Administrador empresa, RRHH, Payroll |
| `people.dependents.delete` | Eliminar derechohabientes | Administrador empresa, RRHH, Payroll |
| `people.dependents.update` | Editar derechohabientes | Administrador empresa, RRHH, Payroll |
| `people.change_requests.view` | Ver solicitudes de cambio de datos | Administrador empresa, RRHH; solo sobre lo propio o asignado: Empleado, Manager |
| `people.change_requests.approve` | Aprobar solicitudes de cambio de datos | Administrador empresa, RRHH |
| `people.change_requests.cancel` | Cancelar solicitudes de cambio de datos | Administrador empresa, RRHH; solo sobre lo propio o asignado: Empleado, Manager |
| `people.change_requests.create` | Crear solicitudes de cambio de datos | Administrador empresa, RRHH; solo sobre lo propio o asignado: Empleado, Manager |
| `people.change_requests.reject` | Rechazar solicitudes de cambio de datos | Administrador empresa, RRHH |
| `people.people.personal_data.view` **(S)** **(+)** | Ver documento, nacimiento, dirección, estado civil y contacto de emergencia | Administrador empresa, RRHH, Payroll, Auditor, SST |
| `people.people.personal_data.edit` **(S)** **(+)** | Editar datos personales sensibles | Administrador empresa, RRHH |
| `people.employments.salary.view` **(S)** **(+)** | Ver sueldo, bonos e historial salarial | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `people.employments.salary.edit` **(S)** **(+)** | Modificar la compensación | Administrador empresa, Payroll |
| `people.people.bank_account.view` **(S)** **(+)** | Ver banco, cuenta y billetera | Administrador empresa, Payroll, Contador |
| `people.people.bank_account.edit` **(S)** **(+)** | Editar datos de pago | Administrador empresa, Payroll |
| `people.people.health_data.view` **(S)** **(+)** | Ver datos de salud y condición de discapacidad | RRHH, SST |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ingreso.** 1) RRHH busca a la persona por documento (evita duplicados; si existe, ofrece reingreso o segunda relación). 2) Completa persona, relación, posición o puesto, jefe, sede, régimen, compensación y contrato inicial. 3) El sistema valida límites del plan y la política de posiciones. 4) Crea la relación «Por ingresar» o «Activa» según la fecha. 5) Emite `employee.hired`, que dispara onboarding, alta en T-Registro, invitación al portal y seguros.

**Reingreso.** 1) Se encuentra a la persona con relaciones anteriores. 2) Se crea una relación nueva; las anteriores quedan intactas. 3) La antigüedad para cada beneficio se calcula según la regla del paquete de país.

**Cese.** 1) RRHH registra fecha, motivo y tipo de cese. 2) Se validan pendientes (activos, préstamos, vacaciones). 3) Se lanza offboarding y liquidación. 4) En la fecha efectiva la relación pasa a «Cesada», se libera la posición y se emite `employee.terminated`.

**Cambio de datos por el colaborador.** 1) Propone el cambio con sustento. 2) Los campos de bajo riesgo se aplican de inmediato; los demás van a aprobación. 3) Aprobado, se actualiza y se audita.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Colaboradores | Tabla con búsqueda, filtros (empresa, unidad, estado), orden, columnas, vistas guardadas, exportar; nuevo ingreso | Prototipo: Personas → Colaboradores |
| Ficha · Resumen | Datos clave, antigüedad, jefe, estado, pendientes, alertas del colaborador | Prototipo: Personas → Colaboradores → Ficha → pestaña Resumen |
| Ficha · Información | Datos personales y laborales, emergencia, derechohabientes, pago, pensión y salud (según permisos) | Prototipo: Personas → Colaboradores → Ficha → pestaña Información |
| Ficha · Contrato | Contrato vigente, renovaciones, periodo de prueba, jornada, documentos | Prototipo: Personas → Colaboradores → Ficha → pestaña Contrato |
| Ficha · Compensación | Sueldo, moneda, bonos, comisiones, asignaciones, historial salarial, posición en banda | Prototipo: Personas → Colaboradores → Ficha → pestaña Compensación |
| Ficha · Historial | Línea de tiempo de movimientos; registrar movimiento | Prototipo: Personas → Colaboradores → Ficha → pestaña Historial |
| Ficha · Actividad | Auditoría del registro | Prototipo: Personas → Colaboradores → Ficha → pestaña Actividad |
| Ficha · pestañas de módulos | Asistencia, Vacaciones, Planilla, Documentos, Activos, Desempeño, Objetivos, Competencias, Capacitaciones, Beneficios, Encuestas, Reconocimientos | Prototipo: Personas → Colaboradores → Ficha → pestañas Asistencia, Planilla, Onboarding, Desempeño, Capacitaciones, Beneficios, Documentos, Reconocimientos y Activos (Vacaciones, Objetivos, Competencias y Encuestas no tienen pestaña en el prototipo) |
| Registrar ingreso | Formulario por pasos con búsqueda de persona, validaciones y resumen | Prototipo: Personas → Colaboradores → Nuevo colaborador |
| Registrar cese | Fecha, motivo, tipo, observaciones, pendientes detectados | Prototipo: Personas → Colaboradores → Ficha → Registrar cese |

## 8. Datos principales

`people`, `employments`, documentos de identidad, direcciones, contactos de emergencia, derechohabientes (`dependents`), datos de pago, afiliaciones (pensión, salud, seguros), solicitudes de cambio de datos, motivos de cese.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección COL.

- **RN-COL-01**: Si se registra un ingreso con un documento de identidad que ya existe en la organización → no se crea otra persona; se ofrece reingreso o relación adicional.
- **RN-COL-02**: Si una persona tiene una relación activa en una empresa → no puede abrirse otra relación activa en la misma empresa con fechas que se solapen.
- **RN-COL-03**: Si se cesa una relación → la persona, sus relaciones anteriores y todo su historial se conservan; nunca se borran.
- **RN-COL-04**: Si la fecha de ingreso es futura → la relación queda «Por ingresar» y pasa a «Activa» automáticamente ese día.
- **RN-COL-05**: Si otro módulo necesita sueldo, jefe, puesto o saldo → lo lee de la relación y su historia vigente; ningún módulo guarda su propia copia editable.
- **RN-COL-06** (parámetro): Si el colaborador propone un cambio en un campo de la lista «requiere aprobación» (parámetro por empresa) → no se aplica hasta que RRHH lo aprueba.
- **RN-COL-07**: Si se registra un hijo como derechohabiente → se exige sustento y fecha de nacimiento; de ello depende la asignación familiar (M-BSO).
- **RN-COL-08**: Si el campo sexo, discapacidad u otro dato sensible se registra → se guarda con finalidad y base legal, y la discapacidad además con consentimiento expreso (M-PRI).
- **RN-COL-09**: Si se registra un cese con fecha anterior al último periodo de planilla cerrado → se rechaza o se exige el flujo de ajuste retroactivo (M-PER).
- **RN-COL-10** (parámetro): Si el cese se registra con activos sin devolver, préstamos con saldo o vacaciones aprobadas futuras → se advierte y se crean tareas de offboarding; el bloqueo es configurable.
- **RN-COL-11**: Si cambia el número de cuenta de pago → requiere permiso de campo, se notifica al titular por un canal distinto y queda auditado.
- **RN-COL-12**: Si el usuario no tiene permiso de un campo sensible → la pestaña o el bloque correspondiente no se muestra ni se envía.
- **RN-COL-13** (parámetro): Si el alta supera el límite de colaboradores del plan → se rechaza con mensaje al administrador.
- **RN-COL-14**: Si la empresa opera en Perú → la ficha incluye los datos del registro laboral (tipo de trabajador, ocupación, situación educativa, indicadores de jornada, tipo de pago, seguros de riesgo) con vigencia, sin duplicar lo que ya vive en persona, relación, contrato o afiliaciones.
- **RN-COL-15**: Si faltan datos obligatorios del registro laboral → la contratación puede guardarse, pero el alta queda bloqueada con la lista de faltantes.

## 10. Estados

- ME-01 · Relación laboral (`05-maquinas-de-estado.md`): estados Por ingresar, Activa, Suspendida, En proceso de cese, Cesada, Anulada.

## 11. Eventos

**Emite:**

- `employee.hired`, `employee.updated`, `employee.terminated`, `employee.rehired`, `employment.activated`, `person.data_change_requested`, `person.data_change_approved`, `dependent.added`, `payment_data.changed`.

**Consume:**

- `candidate.hired` (precarga el ingreso), `offer.accepted`, `offboarding.completed`, `settlement.paid`.

## 12. Integraciones

Validación de identidad contra el registro nacional y verificación de cuenta bancaria, ambas por adaptador opcional.

## 13. Casos borde

- Persona con dos relaciones simultáneas (dos empresas del grupo): dos fichas laborales bajo una misma persona; cada una con su planilla.
- Reingreso el mismo mes del cese.
- Cambio de documento de identidad (carné de extranjería a DNI): se conserva el historial de documentos.
- Ingreso registrado con fecha pasada: dispara cálculos retroactivos en periodos abiertos.
- Fallecimiento: cese con tratamiento especial de beneficiarios (M-LIQ).

## 14. Criterios de aceptación generales

- La ficha reúne todas las pestañas del pedido y cada una refleja datos reales de su módulo.
- Un colaborador creado sigue existiendo y aparece en directorio, organigrama, tableros y auditoría.
- Un administrador sin permiso de sueldos no ve compensación en ningún lugar.
