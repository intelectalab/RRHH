# RPT · Reportes y constructor

Centro de reportes predefinidos y constructor de reportes con permisos sobre datos sensibles.

- **Fase:** Fase 8 · Inteligencia. **Módulo comercial:** Analítica y reportes.
- **Actividades:** 5 (4 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 9.
- **Especificación:** `kit/docs/modulos/M-RPT.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica RPT · Reportes y constructor de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-RPT.md, kit/prompts/RPT.md y la sección RPT de
kit/backlog/backlog.md (grep -n "^### RPT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. RPT-1 · Centro de reportes predefinidos
2. RPT-2 · Constructor de reportes
3. RPT-3 · Permisos sobre datos sensibles en reportes
4. RPT-4 · Guardar, compartir y exportar
5. RPT-5 · Programación de reportes
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-07. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `RPT-1` | Centro de reportes predefinidos | Historia | — | UI-11, ANA-1 |
| 2 | `RPT-2` | Constructor de reportes | Historia | — | RPT-1, CFG-5 |
| 3 | `RPT-3` | Permisos sobre datos sensibles en reportes | Tarea | — | RPT-2, SEG-9, SEG-10 |
| 4 | `RPT-4` | Guardar, compartir y exportar | Historia | — | RPT-3 |
| 5 | `RPT-5` | Programación de reportes | Historia | — | RPT-4, NOT-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ANA-1`, `CFG-5`, `NOT-3`, `SEG-9`, `SEG-10`, `UI-11`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-07 | Réplica de lectura y almacén analítico | Fase 8 | (a) hasta la fase 8, con las consultas de analítica detrás de una interfaz; (b) cuando el constructor de reportes entre en uso; (c) solo con evidencia de carga. |
