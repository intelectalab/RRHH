# TUR · Horarios, jornadas y turnos

Jornadas, horarios, políticas de tiempo, turnos y planificación visual.

- **Fase:** Fase 2 · Tiempo. **Módulo comercial:** Asistencia y turnos.
- **Actividades:** 6 (6 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 21.
- **Especificación:** `kit/docs/modulos/M-TUR.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica TUR · Horarios, jornadas y turnos de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-TUR.md, kit/prompts/TUR.md y la sección TUR de
kit/backlog/backlog.md (grep -n "^### TUR-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. TUR-1 · Jornadas y horarios
2. TUR-3 · Catálogo de turnos
3. TUR-4 · Calendario de turnos con arrastrar y soltar
4. TUR-5 · Asignación masiva y patrones rotativos
5. TUR-2 · Políticas de tiempo por empresa, sede o grupo
6. TUR-6 · Publicación de turnos y cambios entre compañeros
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
| 1 | `TUR-1` | Jornadas y horarios | Historia | — | HIS-1, CFG-3 |
| 2 | `TUR-3` | Catálogo de turnos | Historia | — | TUR-1 |
| 3 | `TUR-4` | Calendario de turnos con arrastrar y soltar | Historia | — | TUR-3, UI-12 |
| 4 | `TUR-5` | Asignación masiva y patrones rotativos | Historia | — | TUR-4 |
| 5 | `TUR-2` | Políticas de tiempo por empresa, sede o grupo | Historia | — | TUR-1, CFG-8 |
| 6 | `TUR-6` | Publicación de turnos y cambios entre compañeros | Historia | — | TUR-4, ESS-1, WFL-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-3`, `CFG-8`, `ESS-1`, `HIS-1`, `UI-12`, `WFL-2`
