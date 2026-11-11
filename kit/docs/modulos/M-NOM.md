# M-NOM · Motor de nómina

Fase 3 · Nómina Perú · Épica `NOM` · Reglas `RN-NOM-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Calcular cualquier planilla de forma reproducible y explicable: un motor genérico que ejecuta conceptos y fórmulas, alimentado por reglas del paquete de país y del régimen laboral, sin reglas legales en código ni en la interfaz.

## 2. Alcance

- Arquitectura motor de nómina + motor de reglas + paquete de país + régimen laboral.
- Tipos de proceso: mensual, quincenal, semanal, adelanto de quincena, gratificación, CTS, utilidades, liquidación, ajuste.
- Entradas: historia con vigencia, asistencia cerrada, ausencias, horas extra, incidencias, préstamos, parámetros.
- Cálculo por lotes, orden de conceptos por dependencias, acumuladores, redondeo, prorrateos por días.
- Trazabilidad: por cada resultado, la fórmula, los valores de entrada y los parámetros con su vigencia.
- Recalculo parcial, cálculo retroactivo por diferencias, cálculo neto a bruto (simulación).
- Planillas separadas por empresa, régimen y tipo de trabajador.

## 3. Fuera de alcance

- Definición de conceptos (M-CPT), parámetros (M-PAR), ciclo del periodo (M-PER). Pago bancario efectivo (M-DEC genera el archivo).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §27 Planillas / payroll.

**Correcciones que incorpora:** C-05 Planilla = motor + reglas + país + régimen.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Payroll opera. El motor no tiene interfaz propia salvo el visor de traza.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.regimes.view` | Ver regímenes laborales | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.entity_annual_figures.view` | Ver ventas y dotación anuales por razón social (umbrales MYPE y tramos agrarios) | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.entity_annual_figures.create` | Crear ventas y dotación anuales por razón social (umbrales MYPE y tramos agrarios) | Administrador empresa, Payroll |
| `payroll.entity_annual_figures.update` | Editar ventas y dotación anuales por razón social (umbrales MYPE y tramos agrarios) | Administrador empresa, Payroll |
| `payroll.construction_works.view` | Ver obras de construcción civil | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.construction_works.create` | Crear obras de construcción civil | Administrador empresa, Payroll |
| `payroll.construction_works.finish` | Dar por terminadas obras de construcción civil | Administrador empresa, Payroll |
| `payroll.construction_works.update` | Editar obras de construcción civil | Administrador empresa, Payroll |
| `payroll.construction_assignments.view` | Ver categorías de construcción civil por obra | Administrador empresa, RRHH, Payroll, Auditor |
| `payroll.construction_assignments.cancel` | Cancelar categorías de construcción civil por obra | Administrador empresa, RRHH, Payroll |
| `payroll.construction_assignments.create` | Crear categorías de construcción civil por obra | Administrador empresa, RRHH, Payroll |
| `payroll.results.view` **(S)** | Ver resultados de planilla por persona y concepto | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.payroll_groups.update` **(+)** | Configurar grupos, procesos y calendarios | Administrador empresa, Payroll |
| `payroll.results.trace.view` **(S)** **(+)** | Ver el detalle de cálculo (fórmula, entradas y parámetros) de un resultado | Administrador empresa, Payroll, Auditor, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Cálculo.** 1) Se fija la fecha de corte y se toma una foto de las entradas. 2) Por cada relación se resuelven régimen, conceptos aplicables y parámetros vigentes. 3) Se ordenan los conceptos por dependencias. 4) Se evalúan fórmulas y se llenan acumuladores. 5) Se guardan resultados con su traza. 6) Se comparan contra el periodo anterior y se marcan anomalías.

**Retroactivo.** 1) Un cambio con vigencia en un periodo cerrado genera una solicitud de diferencia. 2) El motor recalcula ese periodo en memoria con los datos corregidos. 3) La diferencia por concepto entra al periodo abierto como conceptos de reintegro o descuento.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Traza de cálculo | Para una boleta o un concepto: fórmula, valores de entrada, parámetros con vigencia, acumuladores | Prototipo: Nómina → Planilla |
| Grupos y procesos | Grupos de planilla, tipos de proceso, calendario de pagos | Prototipo: Nómina → Configuración de nómina |
| Banco de pruebas | Calcular una persona o un caso hipotético sin guardar | Prototipo: Nómina → Configuración de nómina |

## 8. Datos principales

Tipos de proceso, grupos de planilla, ejecuciones de cálculo (`payroll_runs`), resultados por concepto (`payroll_run_lines`) con traza, acumuladores anuales, fotos de entrada, solicitudes de diferencia retroactiva.  Ventas y dotación anuales por razón social (`legal_entity_annual_figures`), acreditación del régimen (`legal_entity_regimes`), obras y categorías de construcción civil (`pe_construction_works`, `pe_construction_assignments`).

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección NOM.

- **RN-NOM-01**: Si se calcula un periodo → cada parámetro y fórmula se toma con la vigencia de la fecha del periodo, no con la del día del cálculo.
- **RN-NOM-02**: Si se repite el cálculo con las mismas entradas → el resultado es idéntico (el motor no depende de la hora ni del orden de proceso).
- **RN-NOM-03**: Si una regla legal cambia → se publica como nueva versión de parámetro o fórmula con fecha; ningún despliegue de código es necesario.
- **RN-NOM-04**: Si un resultado se guarda → conserva la fórmula, las entradas y la versión de parámetros que lo produjeron.
- **RN-NOM-05**: Si una relación ingresa, cesa o cambia de sueldo dentro del periodo → los conceptos proporcionales se prorratean por tramos de vigencia y días según la base del régimen (parámetro).
- **RN-NOM-06**: Si una fórmula referencia un concepto o parámetro inexistente o crea un ciclo → no se puede publicar.
- **RN-NOM-07**: Si un cambio afecta un periodo cerrado → el motor calcula la diferencia y la lleva al periodo abierto; el periodo cerrado nunca se reescribe.
- **RN-NOM-08** (parámetro): Si se redondea → se aplica la regla de redondeo del paquete de país por concepto (parámetro).
- **RN-NOM-09**: Si la relación pertenece a un régimen → solo se le aplican los conceptos habilitados para ese régimen.
- **RN-NOM-10**: Si falta un dato obligatorio para calcular (régimen pensionario, fecha de ingreso, sueldo vigente) → la relación queda «con error» y no bloquea el cálculo de las demás.
- **RN-NOM-11**: Si la persona tiene dos relaciones en el grupo → cada una se calcula en su planilla; los conceptos que la norma consolida (renta de quinta) reciben la información de la otra como ingreso de otro empleador.
- **RN-NOM-12**: Si se calcula una relación → el motor obtiene del paquete de país la estrategia del régimen vigente en cada tramo (general, micro, pequeña, construcción civil, agrario, formativo); el núcleo no tiene condicionales por régimen (`18-localizacion-peru-detalle-tecnico.md` §4.7).
- **RN-NOM-13** (parámetro): Si se proyecta la renta de quinta → remuneración ordinaria × meses que faltan + gratificaciones por percibir con su bonificación extraordinaria + lo ya percibido + rentas de otros empleadores declaradas, menos la deducción en UIT, con la escala y los divisores del parámetro; el ingreso extraordinario del mes se grava aparte por diferencia de impuestos y se retiene íntegro.
- **RN-NOM-14**: Si el trabajador tiene gastos deducibles adicionales (las 3 UIT de la declaración anual) → el motor no los considera: solo los aplica la administración tributaria en la determinación anual.
- **RN-NOM-15**: Si el trabajador tiene más de un empleador y declaró que otro retiene → la retención de quinta es cero y el libro registra el motivo; si esta razón social retiene, acumula las rentas declaradas de los demás.
- **RN-NOM-16** (parámetro): Si el trabajador no es domiciliado → la retención es el porcentaje de `quinta_no_domiciliado_tasa_pct` sobre el total afecto del mes, sin proyección ni deducción.
- **RN-NOM-17** (parámetro): Si la relación es de construcción civil → el jornal, la bonificación unificada, la movilidad y las demás bonificaciones se toman de la tabla salarial vigente en cada día trabajado, según la categoría del trabajador en la obra (reglas RN-PE-CC del documento 18).
- **RN-NOM-18** (parámetro): Si la relación es agraria con prorrateo → la gratificación y la CTS se pagan dentro de la remuneración diaria como conceptos separados, y la bonificación especial por trabajo agrario se paga como concepto no remunerativo (reglas RN-PE-AGR del documento 18).
- **RN-NOM-19**: Si la razón social no tiene acreditación vigente del régimen especial, o la relación es anterior a la acreditación → no se propone el régimen de micro o pequeña empresa (reglas RN-PE-MYP del documento 18).

## 10. Estados

- ME-19 · Periodo de planilla (`05-maquinas-de-estado.md`): estados Abierto, Calculado, En aprobación, Aprobado, Cerrado, Pagado, Contabilizado.

## 11. Eventos

**Emite:**

- `payroll.run_started`, `payroll.run_completed`, `payroll.run_failed`, `payroll.retro_difference_created`.

**Consume:**

- `employment.compensation_changed`, `employment.assignment_changed`, `attendance.period_closed`, `absence.approved`, `leave.approved`, `parameter.changed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- 50 000 relaciones en un periodo: cálculo por lotes paralelos con reanudación.
- Cambio de régimen pensionario a mitad de mes.
- Neto negativo: se limita por las reglas de descuento máximo y el exceso se traslada.
- Moneda distinta a la de la empresa en el contrato: conversión con tipo de cambio de la fecha de pago.

## 14. Criterios de aceptación generales

- Cualquier monto de una boleta se puede explicar con su traza.
- No existe ninguna tasa, tope ni fórmula legal en el código del motor ni en la interfaz.
