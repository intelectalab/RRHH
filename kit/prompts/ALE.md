# ALE · Alertas explicadas

Motor de alertas con explicación obligatoria y riesgo de rotación con factores visibles.

- **Fase:** Fase 8 · Inteligencia. **Módulo comercial:** Analítica y reportes.
- **Actividades:** 4 (3 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 12.
- **Especificación:** `kit/docs/modulos/M-ALE.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ALE · Alertas explicadas de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ALE.md, kit/prompts/ALE.md y la sección ALE de
kit/backlog/backlog.md (grep -n "^### ALE-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ALE-1 · Motor de alertas
2. ALE-2 · Catálogo de alertas
3. ALE-3 · Explicación obligatoria y gestión de la alerta
4. ALE-4 · Riesgo de rotación explicable
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-20. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ALE-1` | Motor de alertas | Tarea | — | ANA-1, WFL-5 |
| 2 | `ALE-2` | Catálogo de alertas | Historia | — | ALE-1 |
| 3 | `ALE-3` | Explicación obligatoria y gestión de la alerta | Historia | — | ALE-2 |
| 4 | `ALE-4` | Riesgo de rotación explicable | Historia | — | ALE-3, PRI-5, COM-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ANA-1`, `COM-3`, `PRI-5`, `WFL-5`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-20 | Método del riesgo de rotación | Fase 8 | (a). Es explicable, no requiere volumen de datos y encaja con la evaluación de impacto. Nunca dispara acciones automáticas. |
