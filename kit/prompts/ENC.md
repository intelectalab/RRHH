# ENC · Encuestas, clima y eNPS

Constructor de encuestas, anonimato real, eNPS, mapa de calor y planes de acción.

- **Fase:** Fase 6 · Experiencia. **Módulo comercial:** Clima y encuestas.
- **Actividades:** 8 (6 historias y 2 tareas); 0 del MVP. **Operaciones de API:** 13.
- **Especificación:** `kit/docs/modulos/M-ENC.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ENC · Encuestas, clima y eNPS de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ENC.md, kit/prompts/ENC.md y la sección ENC de
kit/backlog/backlog.md (grep -n "^### ENC-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ENC-1 · Constructor de encuestas
2. ENC-2 · Tipos y plantillas de encuesta
3. ENC-3 · Distribución, segmentos y recordatorios
4. ENC-4 · Anonimato real con umbral mínimo
5. ENC-8 · Encuestas automáticas por evento
6. ENC-5 · eNPS y tendencias
7. ENC-6 · Resultados de clima con mapa de calor
8. ENC-7 · Planes de acción con seguimiento
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-26. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ENC-1` | Constructor de encuestas | Historia | — | UI-5 |
| 2 | `ENC-2` | Tipos y plantillas de encuesta | Historia | — | ENC-1 |
| 3 | `ENC-3` | Distribución, segmentos y recordatorios | Historia | — | ENC-2, NOT-1 |
| 4 | `ENC-4` | Anonimato real con umbral mínimo | Tarea | — | ENC-3 |
| 5 | `ENC-8` | Encuestas automáticas por evento | Tarea | — | ENC-3, WFL-5 |
| 6 | `ENC-5` | eNPS y tendencias | Historia | — | ENC-4 |
| 7 | `ENC-6` | Resultados de clima con mapa de calor | Historia | — | ENC-4, UI-12 |
| 8 | `ENC-7` | Planes de acción con seguimiento | Historia | — | ENC-6, WFL-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`NOT-1`, `UI-5`, `UI-12`, `WFL-5`, `WFL-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-26 | Anonimato: umbral mínimo y denuncias anónimas | Fase 6 (encuestas) y fase 7 (denuncias) | umbral como parámetro con un mínimo que la organización no puede bajar; denuncias anónimas admitidas, con aviso al denunciante de sus límites. Validar con legal (D-15). |
