# ANA · People analytics y tableros

Capa analítica con indicadores definidos, tablero ejecutivo y tableros personalizables por rol.

- **Fase:** Fase 8 · Inteligencia. **Módulo comercial:** Analítica y reportes.
- **Actividades:** 6 (4 historias y 2 tareas); 0 del MVP. **Operaciones de API:** 7.
- **Especificación:** `kit/docs/modulos/M-ANA.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ANA · People analytics y tableros de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ANA.md, kit/prompts/ANA.md y la sección ANA de
kit/backlog/backlog.md (grep -n "^### ANA-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ANA-1 · Capa de datos analítica
2. ANA-2 · Catálogo de indicadores
3. ANA-3 · Tablero ejecutivo
4. ANA-6 · Diversidad y distribución salarial con resguardo
5. ANA-4 · Filtros analíticos
6. ANA-5 · Tableros personalizables por rol
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-07, D-20. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ANA-1` | Capa de datos analítica | Tarea | — | TEN-2, HIS-6, PLT-6 |
| 2 | `ANA-2` | Catálogo de indicadores | Tarea | — | ANA-1, PRV-4, ATS-9 |
| 3 | `ANA-3` | Tablero ejecutivo | Historia | — | ANA-2, UI-12 |
| 4 | `ANA-6` | Diversidad y distribución salarial con resguardo | Historia | — | ANA-2, SEG-9 |
| 5 | `ANA-4` | Filtros analíticos | Historia | — | ANA-3 |
| 6 | `ANA-5` | Tableros personalizables por rol | Historia | — | ANA-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ATS-9`, `HIS-6`, `PLT-6`, `PRV-4`, `SEG-9`, `TEN-2`, `UI-12`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-07 | Réplica de lectura y almacén analítico | Fase 8 | (a) hasta la fase 8, con las consultas de analítica detrás de una interfaz; (b) cuando el constructor de reportes entre en uso; (c) solo con evidencia de carga. |
| D-20 | Método del riesgo de rotación | Fase 8 | (a). Es explicable, no requiere volumen de datos y encaja con la evaluación de impacto. Nunca dispara acciones automáticas. |
