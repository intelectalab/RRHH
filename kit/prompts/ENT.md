# ENT · Entrevistas y ofertas

Agenda de entrevistas, evaluación estructurada y ofertas con plantilla y aprobación.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Reclutamiento.
- **Actividades:** 6 (6 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 19.
- **Especificación:** `kit/docs/modulos/M-ENT.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ENT · Entrevistas y ofertas de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ENT.md, kit/prompts/ENT.md y la sección ENT de
kit/backlog/backlog.md (grep -n "^### ENT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ENT-1 · Agenda de entrevistas
2. ENT-2 · Guías y scorecards
3. ENT-3 · Evaluación del candidato por los entrevistadores
4. ENT-4 · Plantillas y generación de la oferta
5. ENT-5 · Flujo de la oferta
6. ENT-6 · Contratación del candidato
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
| 1 | `ENT-1` | Agenda de entrevistas | Historia | — | ATS-5, NOT-3 |
| 2 | `ENT-2` | Guías y scorecards | Historia | — | ENT-1 |
| 3 | `ENT-3` | Evaluación del candidato por los entrevistadores | Historia | — | ENT-2 |
| 4 | `ENT-4` | Plantillas y generación de la oferta | Historia | — | ENT-3, DOC-4 |
| 5 | `ENT-5` | Flujo de la oferta | Historia | — | ENT-4, WFL-2, DOC-6 |
| 6 | `ENT-6` | Contratación del candidato | Historia | — | ENT-5, COL-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ATS-5`, `COL-2`, `DOC-4`, `DOC-6`, `NOT-3`, `WFL-2`
