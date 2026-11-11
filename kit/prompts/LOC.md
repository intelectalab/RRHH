# LOC · Locadores y cuarta categoría

Prestadores de servicios con recibos por honorarios, separados de la planilla.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Locadores.
- **Actividades:** 4 (3 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 12.
- **Especificación:** `kit/docs/modulos/M-LOC.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica LOC · Locadores y cuarta categoría de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-LOC.md, kit/prompts/LOC.md y la sección LOC de
kit/backlog/backlog.md (grep -n "^### LOC-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. LOC-1 · Registro de prestadores
2. LOC-2 · Recibos por honorarios
3. LOC-3 · Retención de cuarta categoría y pago
4. LOC-4 · Declaración y reportes de locadores
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
| 1 | `LOC-1` | Registro de prestadores | Historia | — | COL-1, DOC-1 |
| 2 | `LOC-2` | Recibos por honorarios | Historia | — | LOC-1, WFL-2 |
| 3 | `LOC-3` | Retención de cuarta categoría y pago | Tarea | — | LOC-2, PAR-1 |
| 4 | `LOC-4` | Declaración y reportes de locadores | Historia | — | LOC-3, DEC-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-1`, `DEC-2`, `DOC-1`, `PAR-1`, `WFL-2`
