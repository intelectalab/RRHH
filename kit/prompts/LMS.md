# LMS · Aprendizaje y plan anual

Cursos, lecciones, evaluaciones, certificados, asignaciones, seguimiento y plan anual de capacitación.

- **Fase:** Fase 5 · Desarrollo y compensación. **Módulo comercial:** Aprendizaje.
- **Actividades:** 8 (8 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 18.
- **Especificación:** `kit/docs/modulos/M-LMS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica LMS · Aprendizaje y plan anual de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-LMS.md, kit/prompts/LMS.md y la sección LMS de
kit/backlog/backlog.md (grep -n "^### LMS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. LMS-1 · Cursos, categorías y lecciones
2. LMS-2 · Contenidos de las lecciones
3. LMS-5 · Asignación de cursos
4. LMS-3 · Evaluaciones del curso
5. LMS-7 · Plan anual de capacitación
6. LMS-6 · Seguimiento del aprendizaje
7. LMS-8 · Planificado frente a ejecutado
8. LMS-4 · Certificados
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
| 1 | `LMS-1` | Cursos, categorías y lecciones | Historia | — | PLT-7, UI-12 |
| 2 | `LMS-2` | Contenidos de las lecciones | Historia | — | LMS-1 |
| 3 | `LMS-5` | Asignación de cursos | Historia | — | LMS-1, WFL-5 |
| 4 | `LMS-3` | Evaluaciones del curso | Historia | — | LMS-2 |
| 5 | `LMS-7` | Plan anual de capacitación | Historia | — | LMS-5, WFL-2 |
| 6 | `LMS-6` | Seguimiento del aprendizaje | Historia | — | LMS-5, ESS-1 |
| 7 | `LMS-8` | Planificado frente a ejecutado | Historia | — | LMS-7, LMS-6 |
| 8 | `LMS-4` | Certificados | Historia | — | LMS-3, DOC-5 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`DOC-5`, `ESS-1`, `PLT-7`, `UI-12`, `WFL-2`, `WFL-5`
