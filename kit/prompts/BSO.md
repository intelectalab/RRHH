# BSO · Beneficios sociales

Gratificaciones, CTS, utilidades, asignación familiar y remuneración vacacional.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 5 (3 historias y 2 tareas); 5 del MVP. **Operaciones de API:** 13.
- **Especificación:** `kit/docs/modulos/M-BSO.md`.
- **Ya avanzado en el kit:** `kit/referencia/calculadora-planilla/`: provisiones de gratificación, bonificación extraordinaria y CTS.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica BSO · Beneficios sociales de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-BSO.md, kit/prompts/BSO.md y la sección BSO de
kit/backlog/backlog.md (grep -n "^### BSO-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. BSO-4 · Asignación familiar
2. BSO-5 · Remuneración vacacional e indemnización vacacional
3. BSO-1 · Gratificaciones
4. BSO-3 · Utilidades
5. BSO-2 · CTS
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
| 1 | `BSO-4` | Asignación familiar | Tarea | MVP | PER-4, COL-6 |
| 2 | `BSO-5` | Remuneración vacacional e indemnización vacacional | Tarea | MVP | PER-4, VAC-6 |
| 3 | `BSO-1` | Gratificaciones | Historia | MVP | PER-8, PAR-5 |
| 4 | `BSO-3` | Utilidades | Historia | MVP | PER-8, AUS-6 |
| 5 | `BSO-2` | CTS | Historia | MVP | BSO-1, COL-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUS-6`, `COL-6`, `COL-8`, `PAR-5`, `PER-4`, `PER-8`, `VAC-6`
