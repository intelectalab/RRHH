# REC · Reconocimientos

Reconocimientos entre compañeros ligados a valores, insignias y muro opcional.

- **Fase:** Fase 6 · Experiencia. **Módulo comercial:** Clima y encuestas.
- **Actividades:** 4 (4 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 4.
- **Especificación:** `kit/docs/modulos/M-REC.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica REC · Reconocimientos de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-REC.md, kit/prompts/REC.md y la sección REC de
kit/backlog/backlog.md (grep -n "^### REC-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. REC-1 · Valores e insignias
2. REC-2 · Reconocer a un compañero
3. REC-3 · Muro de reconocimientos
4. REC-4 · Reportes de reconocimiento
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
| 1 | `REC-1` | Valores e insignias | Historia | — | CFG-1 |
| 2 | `REC-2` | Reconocer a un compañero | Historia | — | REC-1, NOT-1 |
| 3 | `REC-3` | Muro de reconocimientos | Historia | — | REC-2 |
| 4 | `REC-4` | Reportes de reconocimiento | Historia | — | REC-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-1`, `NOT-1`
