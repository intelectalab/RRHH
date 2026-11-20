# PRV · Provisiones y asientos contables

Provisiones mensuales, asiento por centro de costo y exportación a ERP.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 5 (4 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 6.
- **Especificación:** `kit/docs/modulos/M-PRV.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PRV · Provisiones y asientos contables de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PRV.md, kit/prompts/PRV.md y la sección PRV de
kit/backlog/backlog.md (grep -n "^### PRV-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PRV-5 · Simulador público de costo laboral
2. PRV-1 · Provisiones mensuales
3. PRV-2 · Asiento contable de planilla
4. PRV-3 · Exportación contable configurable
5. PRV-4 · Reporte de costo laboral
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: ninguna asignada. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `PRV-5` | Simulador público de costo laboral | Historia | ola 2 | NOM-9, PER-7 |
| 2 | `PRV-1` | Provisiones mensuales | Tarea | — | PER-8, BSO-2 |
| 3 | `PRV-2` | Asiento contable de planilla | Historia | — | PRV-1, CPT-4 |
| 4 | `PRV-3` | Exportación contable configurable | Historia | — | PRV-2 |
| 5 | `PRV-4` | Reporte de costo laboral | Historia | — | PRV-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`BSO-2`, `CPT-4`, `NOM-9`, `PER-7`, `PER-8`
