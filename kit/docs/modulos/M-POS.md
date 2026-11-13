# M-POS · Posiciones y headcount

Fase 1 · Core HR · Épica `POS` · Reglas `RN-POS-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Gestionar la plantilla por posiciones (plazas presupuestadas): cuántas hay, cuáles están ocupadas o vacantes, cuánto cuestan y cómo se comparan con el presupuesto y con escenarios.

## 2. Alcance

- Posición (`positions`): puesto de catálogo, unidad, sede, centro de costo, jefe (otra posición), tipo, fecha de vigencia, costo presupuestado, criticidad.
- Estados: propuesta, aprobada, vacante, en reclutamiento, ocupada, congelada, cerrada.
- Solicitud de posición con aprobación y presupuesto; enlace a vacante (M-ATS) y a contratación.
- Política opcional «no contratar sin posición aprobada».
- Presupuesto de plantilla por periodo, costo proyectado, desviaciones.
- Escenarios: actual, conservador, crecimiento, personalizado.
- Organigrama por posiciones con vacantes y costo.

## 3. Fuera de alcance

- Proceso de selección (M-ATS). Cálculo real de planilla (M-NOM); aquí se usa costo estimado con factores de carga.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §5 Headcount y planificación de plantilla.

**Correcciones que incorpora:** C-02 Puesto ≠ posición.

**Ampliaciones que incorpora:** E-10 Organigrama por posiciones.

## 5. Actores y permisos

- Manager solicita. RRHH y finanzas aprueban. Administrador define política y presupuesto.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `org.positions.view` | Ver posiciones | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, Contador |
| `org.positions.approve` | Aprobar posiciones | Administrador empresa, RRHH |
| `org.positions.close` | Cerrar posiciones | Administrador empresa, RRHH |
| `org.positions.create` | Crear posiciones | Administrador empresa, RRHH |
| `org.positions.freeze` | Congelar posiciones | Administrador empresa, RRHH |
| `org.positions.unfreeze` | Descongelar posiciones | Administrador empresa, RRHH |
| `org.positions.update` | Editar posiciones | Administrador empresa, RRHH |
| `org.position_requests.create` **(+)** | Solicitar una nueva posición o un reemplazo | Administrador empresa, RRHH, Manager |
| `org.position_requests.view` **(+)** | Ver solicitudes de posición (las propias; todas con `org.positions.approve`) | Administrador empresa, RRHH, Manager, Auditor, Contador |
| `org.headcount_plans.view` | Ver planes y escenarios de plantilla | Administrador empresa, RRHH, Auditor, Contador |
| `org.headcount_plans.approve` | Aprobar planes y escenarios de plantilla | Administrador empresa |
| `org.headcount_plans.close` | Cerrar planes y escenarios de plantilla | Administrador empresa, RRHH |
| `org.headcount_plans.create` | Crear planes y escenarios de plantilla | Administrador empresa, RRHH |
| `org.headcount_plans.submit` | Enviar planes y escenarios de plantilla | Administrador empresa, RRHH |
| `org.headcount_plans.update` | Editar planes y escenarios de plantilla | Administrador empresa, RRHH |
| `org.positions.budget.view` **(S)** **(+)** | Ver costo presupuestado y proyectado de posiciones | Administrador empresa, RRHH, Payroll, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Solicitud de posición.** 1) El manager solicita (puesto, unidad, motivo, fecha, costo estimado). 2) El motor de aprobaciones la envía a RRHH y finanzas. 3) Aprobada, se crea la posición «Aprobada» con cargo al presupuesto. 4) RRHH abre la vacante: la posición pasa a «En reclutamiento». 5) Al contratar, se asigna la relación laboral y pasa a «Ocupada».

**Cese o traslado del ocupante.** 1) Al cesar o moverse, la posición vuelve a «Vacante». 2) RRHH decide reemplazar, congelar o cerrar.

**Escenario.** 1) Se duplica la plantilla actual. 2) Se añaden, congelan o eliminan posiciones y se ajustan fechas y costos. 3) Se compara con el presupuesto y con otros escenarios. 4) Un escenario aprobado puede convertirse en solicitudes reales.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Posiciones | Tabla con puesto, unidad, ocupante, estado, costo (con permiso); filtros por estado y unidad; crear, congelar, cerrar | Prototipo: Personas → Headcount |
| Solicitudes de posición | Lista con estado de aprobación; detalle y decisión | Prototipo: Personas → Headcount |
| Headcount | Aprobadas, ocupadas, vacantes, congeladas; presupuesto frente a real y desviación por unidad | Prototipo: Personas → Headcount |
| Escenarios | Comparador de escenarios con costo y dotación por periodo | Prototipo: Personas → Headcount |
| Organigrama por posiciones | Árbol con plazas ocupadas y vacantes | Prototipo: Personas → Headcount |

## 8. Datos principales

`positions` (con vigencia), solicitudes de posición, presupuesto de plantilla por periodo y centro de costo, escenarios y sus posiciones, factores de carga para costo estimado.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección POS.

- **RN-POS-01** (parámetro): Si la empresa tiene activa la política «contratar solo contra posición» → no se puede registrar un ingreso ni emitir una oferta sin una posición aprobada o vacante.
- **RN-POS-02**: Si una posición ya tiene un ocupante activo → no admite otro, salvo que esté marcada como compartida con número de cupos.
- **RN-POS-03**: Si una posición está congelada o cerrada → no se puede abrir vacante ni asignar ocupante.
- **RN-POS-04**: Si el ocupante cesa o se traslada → la posición pasa a «Vacante» en la fecha efectiva.
- **RN-POS-05** (parámetro): Si el costo de una solicitud supera el presupuesto disponible de la unidad → la aprobación exige el nivel adicional configurado (parámetro).
- **RN-POS-06**: Si se cierra una posición ocupada → se rechaza; primero debe quedar vacante.
- **RN-POS-07**: Si se calcula el costo proyectado → usa la compensación vigente del ocupante o el punto medio de la banda si está vacante, más los factores de carga del régimen (parámetros del paquete de país).
- **RN-POS-08**: Si un escenario se modifica → no altera posiciones reales hasta convertirlo de forma explícita en solicitudes.

## 10. Estados

- ME-03 · Posición (`05-maquinas-de-estado.md`): estados Propuesta, Aprobada, Vacante, En reclutamiento, Ocupada, Congelada, Cerrada.
- ME-04 · Solicitud de posición (`05-maquinas-de-estado.md`): estados Borrador, En aprobación, Aprobada, Rechazada, Cancelada.

## 11. Eventos

**Emite:**

- `position.requested`, `position.approved`, `position.opened`, `position.filled`, `position.vacated`, `position.frozen`, `position.closed`.

**Consume:**

- `employee.hired`, `employee.terminated`, `employment.assignment_changed`, `requisition.published`, `requisition.closed`, `approval.approved`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Reemplazo con traslape: nuevo ocupante entra antes de que salga el anterior (cupo temporal doble con fecha fin).
- Posición con ocupante en licencia larga y suplente temporal.
- Empresa sin política activa: se crea la posición de forma implícita al registrar el ingreso.

## 14. Criterios de aceptación generales

- El flujo solicitud → aprobación → presupuesto → reclutamiento → contratación → ocupada funciona de extremo a extremo.
- El tablero muestra aprobadas, ocupadas, vacantes, nuevas, congeladas, eliminadas, costo proyectado y desviación.
