# PER · Periodos, incidencias, cálculo, simulación y cierre

Proceso de planilla de punta a punta: periodo, incidencias, cálculo, simulación, revisión, aprobación, cierre y pago.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 13 (9 historias y 4 tareas); 13 del MVP. **Operaciones de API:** 19.
- **Especificación:** `kit/docs/modulos/M-PER.md`.
- **Ya avanzado en el kit:** `kit/referencia/calculadora-planilla/`: quinta proyectada, pagos extraordinarios y regularización de diciembre.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PER · Periodos, incidencias, cálculo, simulación y cierre de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PER.md, kit/prompts/PER.md y la sección PER de
kit/backlog/backlog.md (grep -n "^### PER-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PER-1 · Periodos de planilla
2. PER-2 · Incidencias del periodo
3. PER-3 · Consumo de asistencia y ausencias
4. PER-4 · Cálculo de la planilla
5. PER-5 · Renta de quinta categoría
6. PER-6 · Aportes previsionales y de salud
7. PER-7 · Simulación y detección de anomalías
8. PER-8 · Revisión, aprobación y cierre
9. PER-11 · Libro de retenciones de quinta
10. PER-9 · Reapertura controlada y planilla complementaria
11. PER-10 · Pago de la planilla
12. PER-12 · Importación de acumulados de planilla del ejercicio
13. PER-13 · Comparación con el sistema anterior durante la planilla en paralelo
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-07. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `PER-1` | Periodos de planilla | Historia | MVP | NOM-1, PLT-8 |
| 2 | `PER-2` | Incidencias del periodo | Historia | MVP | PER-1, CPT-6 |
| 3 | `PER-3` | Consumo de asistencia y ausencias | Tarea | MVP | PER-1, NOM-5 |
| 4 | `PER-4` | Cálculo de la planilla | Historia | MVP | PER-2, PER-3, NOM-8, CPT-5 |
| 5 | `PER-5` | Renta de quinta categoría | Tarea | MVP | PER-4, PAR-3 |
| 6 | `PER-6` | Aportes previsionales y de salud | Tarea | MVP | PER-4, PAR-2 |
| 7 | `PER-7` | Simulación y detección de anomalías | Historia | MVP | PER-4 |
| 8 | `PER-8` | Revisión, aprobación y cierre | Historia | MVP | PER-7, WFL-2 |
| 9 | `PER-11` | Libro de retenciones de quinta | Tarea | MVP | PER-5 |
| 10 | `PER-9` | Reapertura controlada y planilla complementaria | Historia | MVP | PER-8 |
| 11 | `PER-10` | Pago de la planilla | Historia | MVP | PER-8, COL-8 |
| 12 | `PER-12` | Importación de acumulados de planilla del ejercicio | Historia | MVP | IMP-1, PER-1, PER-5, PER-11 |
| 13 | `PER-13` | Comparación con el sistema anterior durante la planilla en paralelo | Historia | MVP | PER-12, PER-7, IMP-7 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-8`, `CPT-5`, `CPT-6`, `IMP-1`, `IMP-7`, `NOM-1`, `NOM-5`, `NOM-8`, `PAR-2`, `PAR-3`, `PLT-8`, `WFL-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-07 | Reapertura de planilla cerrada y retroactivos | Fase 3 | (b). Tras declarar o pagar, solo ajustes en el siguiente periodo y archivo rectificatorio. |
