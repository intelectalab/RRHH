# DIS · Casos disciplinarios

Procedimientos disciplinarios confidenciales con descargos, medidas y seguimiento.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Denuncias.
- **Actividades:** 4 (4 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 8.
- **Especificación:** `kit/docs/modulos/M-DIS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica DIS · Casos disciplinarios de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-DIS.md, kit/prompts/DIS.md y la sección DIS de
kit/backlog/backlog.md (grep -n "^### DIS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. DIS-1 · Registro del caso disciplinario
2. DIS-2 · Imputación y descargos
3. DIS-3 · Medidas disciplinarias
4. DIS-4 · Seguimiento e historial disciplinario
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
| 1 | `DIS-1` | Registro del caso disciplinario | Historia | — | COL-3, SEG-11 |
| 2 | `DIS-2` | Imputación y descargos | Historia | — | DIS-1, DOC-6 |
| 3 | `DIS-3` | Medidas disciplinarias | Historia | — | DIS-2, AUS-2 |
| 4 | `DIS-4` | Seguimiento e historial disciplinario | Historia | — | DIS-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUS-2`, `COL-3`, `DOC-6`, `SEG-11`
