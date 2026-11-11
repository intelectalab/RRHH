# CIN · Comunicación interna

Noticias, comunicados y eventos segmentados con confirmación de lectura.

- **Fase:** Fase 6 · Experiencia. **Módulo comercial:** Clima y encuestas.
- **Actividades:** 4 (4 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 7.
- **Especificación:** `kit/docs/modulos/M-CIN.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CIN · Comunicación interna de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CIN.md, kit/prompts/CIN.md y la sección CIN de
kit/backlog/backlog.md (grep -n "^### CIN-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CIN-1 · Publicaciones
2. CIN-2 · Segmentación de la audiencia
3. CIN-3 · Confirmación de lectura
4. CIN-4 · Cumpleaños, aniversarios y eventos
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
| 1 | `CIN-1` | Publicaciones | Historia | — | PLT-7, UI-5 |
| 2 | `CIN-2` | Segmentación de la audiencia | Historia | — | CIN-1, NOT-1 |
| 3 | `CIN-3` | Confirmación de lectura | Historia | — | CIN-2, WFL-9 |
| 4 | `CIN-4` | Cumpleaños, aniversarios y eventos | Historia | — | CIN-1, CFG-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-8`, `NOT-1`, `PLT-7`, `UI-5`, `WFL-9`
