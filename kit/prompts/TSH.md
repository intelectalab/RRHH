# TSH · Hojas de tiempo por proyecto

Registro de tiempo por proyecto y tarea con aprobación y costeo.

- **Fase:** Fase 2 · Tiempo. **Módulo comercial:** Hojas de tiempo.
- **Actividades:** 4 (4 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 12.
- **Especificación:** `kit/docs/modulos/M-TSH.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica TSH · Hojas de tiempo por proyecto de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-TSH.md, kit/prompts/TSH.md y la sección TSH de
kit/backlog/backlog.md (grep -n "^### TSH-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. TSH-1 · Proyectos y tareas imputables
2. TSH-2 · Hoja de tiempo
3. TSH-3 · Aprobación de hojas de tiempo
4. TSH-4 · Costeo y reporte por proyecto
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
| 1 | `TSH-1` | Proyectos y tareas imputables | Historia | — | ORG-4 |
| 2 | `TSH-2` | Hoja de tiempo | Historia | — | TSH-1, ASI-8 |
| 3 | `TSH-3` | Aprobación de hojas de tiempo | Historia | — | TSH-2, WFL-2 |
| 4 | `TSH-4` | Costeo y reporte por proyecto | Historia | — | TSH-3, HIS-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-8`, `HIS-6`, `ORG-4`, `WFL-2`
