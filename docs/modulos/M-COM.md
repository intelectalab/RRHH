# M-COM · Compensaciones, bandas, revisión salarial e igualdad salarial

Fase 5 · Desarrollo y compensación · Épica `COM` · Reglas `RN-COM-nn` · Módulo comercial: Compensaciones (`compensation`)

## 1. Objetivo

Administrar la compensación con criterio y evidencia: estructura, bandas, ciclos de revisión con presupuesto y cumplimiento de la igualdad salarial.

## 2. Alcance

- Componentes: salario base, variable, bonos, comisiones, asignaciones, beneficios valorizados; compensación total.
- Bandas por puesto, nivel y categoría (mínimo, medio, máximo, moneda, vigencia); posición en banda y compa-ratio.
- Ciclos de revisión: manager propone → RRHH revisa → finanzas aprueba → dirección aprueba → actualización con vigencia; presupuesto por unidad; lineamientos por desempeño y posición en banda.
- Igualdad salarial (Ley 30709): cuadro de categorías y funciones, política salarial, brecha por categoría y sexo con criterios objetivos, evidencia de comunicación.
- Simulaciones de costo.

## 3. Fuera de alcance

- Pago (M-PER). Historial de compensación (M-HIS guarda; aquí se decide).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §33 Compensaciones; §34 Ciclos de revisión salarial.

**Correcciones que incorpora:** C-11 Una sola fuente de verdad.

**Ampliaciones que incorpora:** E-05 Igualdad salarial (Ley 30709).

## 5. Actores y permisos

- RRHH o especialista de compensaciones administra. Manager propone. Finanzas y dirección aprueban. Payroll aplica.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `people.compensations.view` **(S)** | Ver registros de compensación con vigencia | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `people.compensations.cancel` **(S)** | Cancelar registros de compensación con vigencia | Administrador empresa, Payroll |
| `people.compensations.create` **(S)** | Crear registros de compensación con vigencia | Administrador empresa, Payroll |
| `compensation.bands.view` **(S)** | Ver bandas salariales | Administrador empresa, RRHH, Payroll, Manager |
| `compensation.bands.create` **(S)** | Crear bandas salariales | Administrador empresa, RRHH |
| `compensation.bands.update` **(S)** | Editar bandas salariales | Administrador empresa, RRHH |
| `compensation.cycles.view` | Ver ciclos de revisión salarial | Administrador empresa, RRHH, Payroll, Auditor |
| `compensation.cycles.apply` | Aplicar ciclos de revisión salarial | Administrador empresa, Payroll |
| `compensation.cycles.approve` | Aprobar ciclos de revisión salarial | Administrador empresa |
| `compensation.cycles.close` | Cerrar ciclos de revisión salarial | Administrador empresa, RRHH |
| `compensation.cycles.create` | Crear ciclos de revisión salarial | Administrador empresa, RRHH |
| `compensation.cycles.open` | Abrir ciclos de revisión salarial | Administrador empresa, RRHH |
| `compensation.cycles.submit` | Enviar ciclos de revisión salarial | Administrador empresa, RRHH |
| `compensation.cycles.update` | Editar ciclos de revisión salarial | Administrador empresa, RRHH |
| `compensation.proposals.view` **(S)** | Ver propuestas de incremento | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager |
| `compensation.proposals.approve` **(S)** | Aprobar propuestas de incremento | Administrador empresa, RRHH |
| `compensation.proposals.create` **(S)** | Crear propuestas de incremento | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager |
| `compensation.proposals.reject` **(S)** | Rechazar propuestas de incremento | Administrador empresa, RRHH |
| `compensation.proposals.submit` **(S)** | Enviar propuestas de incremento | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager |
| `compensation.proposals.update` **(S)** | Editar propuestas de incremento | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager |
| `compensation.pay_equity.view` **(S)** | Ver cuadro de categorías, política salarial y reporte de brecha | Administrador empresa, RRHH, Auditor |
| `compensation.pay_equity.create` **(S)** | Crear cuadro de categorías, política salarial y reporte de brecha | Administrador empresa, RRHH |
| `compensation.pay_equity.update` **(S)** | Editar cuadro de categorías, política salarial y reporte de brecha | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ciclo de revisión.** 1) RRHH crea el ciclo con población, presupuesto, lineamientos y fecha de vigencia. 2) Cada manager propone dentro de su presupuesto, viendo desempeño y posición en banda. 3) RRHH revisa y ajusta. 4) Finanzas aprueba el costo. 5) Dirección aprueba. 6) Payroll aplica: se crean los registros de compensación con vigencia. 7) Se generan las cartas y la evidencia de comunicación.

**Igualdad salarial.** 1) Se asigna cada puesto a una categoría con funciones, responsabilidad y requisitos. 2) Se publica la política salarial y se comunica con acuse. 3) El reporte calcula la brecha por categoría y sexo. 4) Cada diferencia relevante se justifica con criterios objetivos (experiencia, formación, antigüedad, desempeño) o genera un plan de corrección.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Salarios | Compensación por persona con componentes y posición en banda | Prototipo: Compensación → Bandas salariales |
| Bandas | Bandas por puesto y nivel; personas fuera de banda | Prototipo: Compensación → Bandas salariales |
| Ciclos de revisión | Ciclos con etapa, presupuesto usado y pendiente | Prototipo: Compensación → Revisión salarial |
| Propuestas del manager | Equipo con sueldo actual, banda, desempeño, propuesta y presupuesto restante | Prototipo: Compensación → Revisión salarial |
| Igualdad salarial | Cuadro de categorías, política, brecha por categoría y sexo, justificaciones, acuses | Prototipo: Compensación → Igualdad salarial |

## 8. Datos principales

Componentes de compensación, bandas (`salary_bands`), ciclos (`compensation_review_cycles`), propuestas, presupuestos, cuadro de categorías, política salarial y versiones, acuses de comunicación, justificaciones de brecha.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección COM.

- **RN-COM-01**: Si se aplica un ciclo → cada incremento aprobado crea un registro de compensación con vigencia en M-HIS; el módulo no guarda sueldos propios.
- **RN-COM-02** (parámetro): Si las propuestas de un manager superan su presupuesto asignado → no puede enviarlas sin solicitar excepción.
- **RN-COM-03** (parámetro): Si una propuesta deja el sueldo fuera de la banda del puesto → exige justificación y aprobación del nivel adicional (parámetro).
- **RN-COM-04**: Si una etapa del ciclo no está aprobada → la siguiente no puede iniciarse.
- **RN-COM-05**: Si el ciclo está aplicado → no se modifica; correcciones por movimiento individual.
- **RN-COM-06**: Si quien propone el incremento es el beneficiario o su subordinado directo → se rechaza; nadie propone ni aprueba su propio sueldo.
- **RN-COM-07**: Si un puesto no tiene categoría asignada → se reporta como incumplimiento del cuadro de categorías y funciones.
- **RN-COM-08**: Si una persona ingresa, cambia de puesto o de remuneración → se genera la comunicación de la política salarial y se registra su acuse como evidencia.
- **RN-COM-09** (parámetro): Si la brecha salarial entre sexos dentro de una categoría supera el umbral de revisión (parámetro de la organización) → se exige registrar la justificación con criterios objetivos o un plan de corrección.
- **RN-COM-10** (parámetro): Si un grupo del reporte de brecha tiene menos personas que el mínimo de confidencialidad (parámetro) → se muestra agregado para no revelar sueldos individuales.
- **RN-COM-11**: Si el sistema sugiere un incremento por lineamiento → es una referencia; la propuesta y la aprobación son humanas.
- **RN-COM-12**: Si se compara la posición en banda → usa la banda vigente del puesto y la moneda de la relación, con conversión por tipo de cambio de la fecha.

## 10. Estados

- ME-29 · Ciclo de revisión salarial (`05-maquinas-de-estado.md`): estados Borrador, Propuestas de managers, Revisión de RRHH, Aprobación de finanzas, Aprobación de dirección, Aprobado, Aplicado, Cancelado.

## 11. Eventos

**Emite:**

- `compensation_cycle.launched`, `compensation_cycle.proposals_submitted`, `compensation_cycle.approved`, `compensation_cycle.applied`, `salary_band.changed`, `pay_equity.policy_published`, `pay_equity.communication_acknowledged`.

**Consume:**

- `review.published`, `employment.compensation_changed`, `employment.assignment_changed`, `employee.hired`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Persona que cambia de jefe durante el ciclo.
- Incremento mínimo obligatorio por alza de la RMV.
- Tiempo parcial: comparación en equivalente a jornada completa.
- Categoría con un solo sexo.

## 14. Criterios de aceptación generales

- Hay bandas con posición del trabajador, ciclos con presupuesto y cuatro niveles de aprobación, y el reporte de igualdad salarial con cuadro, política, brecha y evidencias.
