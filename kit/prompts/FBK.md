# FBK · Reuniones 1:1 y feedback

Reuniones individuales con agenda y compromisos, y feedback continuo integrado al desempeño.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Desempeño y objetivos.
- **Actividades:** 4 (3 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 9.
- **Especificación:** `kit/docs/modulos/M-FBK.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica FBK · Reuniones 1:1 y feedback de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-FBK.md, kit/prompts/FBK.md y la sección FBK de
kit/backlog/backlog.md (grep -n "^### FBK-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. FBK-1 · Reuniones 1:1
2. FBK-2 · Notas privadas y seguimiento de compromisos
3. FBK-3 · Feedback continuo
4. FBK-4 · Feedback y 1:1 en la evaluación de desempeño
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
| 1 | `FBK-1` | Reuniones 1:1 | Historia | — | HIS-6, UI-12 |
| 2 | `FBK-2` | Notas privadas y seguimiento de compromisos | Historia | — | FBK-1 |
| 3 | `FBK-3` | Feedback continuo | Historia | — | FBK-1, WFL-9 |
| 4 | `FBK-4` | Feedback y 1:1 en la evaluación de desempeño | Tarea | — | FBK-3, DES-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`DES-4`, `HIS-6`, `UI-12`, `WFL-9`
