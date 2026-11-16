# HSD · Mesa de ayuda y base de conocimiento

Solicitudes a RRHH con colas, SLA y base de conocimiento vinculada.

- **Fase:** Fase 6 · Experiencia. **Módulo comercial:** Mesa de ayuda.
- **Actividades:** 7 (7 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 0.
- **Especificación:** `kit/docs/modulos/M-HSD.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica HSD · Mesa de ayuda y base de conocimiento de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-HSD.md, kit/prompts/HSD.md y la sección HSD de
kit/backlog/backlog.md (grep -n "^### HSD-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. HSD-5 · Base de conocimiento
2. HSD-1 · Catálogo de solicitudes y formularios
3. HSD-2 · Tickets y estados
4. HSD-3 · SLA configurable
5. HSD-4 · Colas, asignación y respuestas tipo
6. HSD-6 · Buscador y artículos sugeridos
7. HSD-7 · Satisfacción y reportes de la mesa
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
| 1 | `HSD-5` | Base de conocimiento | Historia | — | AYU-4, CIN-2 |
| 2 | `HSD-1` | Catálogo de solicitudes y formularios | Historia | — | ESS-5, CFG-4 |
| 3 | `HSD-2` | Tickets y estados | Historia | — | HSD-1 |
| 4 | `HSD-3` | SLA configurable | Historia | — | HSD-2, CFG-8 |
| 5 | `HSD-4` | Colas, asignación y respuestas tipo | Historia | — | HSD-2 |
| 6 | `HSD-6` | Buscador y artículos sugeridos | Historia | — | HSD-5, HSD-2 |
| 7 | `HSD-7` | Satisfacción y reportes de la mesa | Historia | — | HSD-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AYU-4`, `CFG-4`, `CFG-8`, `CIN-2`, `ESS-5`
