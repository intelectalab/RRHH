# MSS · Portal del manager

Experiencia del responsable de equipo: su gente, solicitudes, fechas clave y aprobaciones.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 4 (4 historias y 0 tareas); 4 del MVP. **Operaciones de API:** 2.
- **Especificación:** `kit/docs/modulos/M-MSS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica MSS · Portal del manager de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-MSS.md, kit/prompts/MSS.md y la sección MSS de
kit/backlog/backlog.md (grep -n "^### MSS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. MSS-1 · Inicio del manager
2. MSS-2 · Mi equipo
3. MSS-3 · Aprobaciones y solicitudes del equipo
4. MSS-4 · Fechas clave y alertas del equipo
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
| 1 | `MSS-1` | Inicio del manager | Historia | MVP | ESS-1, SEG-10 |
| 2 | `MSS-2` | Mi equipo | Historia | MVP | MSS-1, COL-9 |
| 3 | `MSS-3` | Aprobaciones y solicitudes del equipo | Historia | MVP | MSS-1, WFL-4, HIS-2 |
| 4 | `MSS-4` | Fechas clave y alertas del equipo | Historia | MVP | MSS-1, CON-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-9`, `CON-4`, `ESS-1`, `HIS-2`, `SEG-10`, `WFL-4`
