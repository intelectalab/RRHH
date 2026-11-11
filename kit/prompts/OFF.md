# OFF · Offboarding

Proceso de salida con motivos, lista de verificación, entrevista de salida y cierre de accesos.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Onboarding y offboarding.
- **Actividades:** 5 (4 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 7.
- **Especificación:** `kit/docs/modulos/M-OFF.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica OFF · Offboarding de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-OFF.md, kit/prompts/OFF.md y la sección OFF de
kit/backlog/backlog.md (grep -n "^### OFF-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. OFF-1 · Inicio del proceso de salida
2. OFF-2 · Lista de verificación de salida
3. OFF-4 · Desactivación de accesos al cese
4. OFF-3 · Entrevista y encuesta de salida
5. OFF-5 · Liquidación y documentos de salida en el proceso
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-23. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `OFF-1` | Inicio del proceso de salida | Historia | — | COL-11, WFL-2 |
| 2 | `OFF-2` | Lista de verificación de salida | Historia | — | OFF-1, WFL-8 |
| 3 | `OFF-4` | Desactivación de accesos al cese | Tarea | — | OFF-1, SEG-6 |
| 4 | `OFF-3` | Entrevista y encuesta de salida | Historia | — | OFF-2 |
| 5 | `OFF-5` | Liquidación y documentos de salida en el proceso | Historia | — | OFF-2, LIQ-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-11`, `LIQ-4`, `SEG-6`, `WFL-2`, `WFL-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-23 | Acceso del colaborador tras el cese | Fase 1 | (b), con duración configurable por organización; no cuenta para el límite del plan. |
