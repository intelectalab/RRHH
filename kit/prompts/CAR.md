# CAR · Desarrollo, carrera, sucesión y 9-box

Planes de desarrollo, rutas de carrera, sucesión de posiciones críticas y matriz 9-box.

- **Fase:** Fase 5 · Desarrollo y compensación. **Módulo comercial:** Carrera y sucesión.
- **Actividades:** 7 (7 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 22.
- **Especificación:** `kit/docs/modulos/M-CAR.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CAR · Desarrollo, carrera, sucesión y 9-box de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CAR.md, kit/prompts/CAR.md y la sección CAR de
kit/backlog/backlog.md (grep -n "^### CAR-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CAR-2 · Rutas de carrera
2. CAR-1 · Planes de desarrollo individual
3. CAR-6 · Matriz 9-box
4. CAR-3 · Brechas frente al siguiente paso
5. CAR-4 · Posiciones críticas y sucesores
6. CAR-5 · Matriz de sucesión
7. CAR-7 · Sesión de revisión de talento
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
| 1 | `CAR-2` | Rutas de carrera | Historia | — | CMP-2 |
| 2 | `CAR-1` | Planes de desarrollo individual | Historia | — | DES-6, CMP-4, LMS-5 |
| 3 | `CAR-6` | Matriz 9-box | Historia | — | DES-6 |
| 4 | `CAR-3` | Brechas frente al siguiente paso | Historia | — | CAR-2, CAR-1 |
| 5 | `CAR-4` | Posiciones críticas y sucesores | Historia | — | POS-1, CAR-1 |
| 6 | `CAR-5` | Matriz de sucesión | Historia | — | CAR-4 |
| 7 | `CAR-7` | Sesión de revisión de talento | Historia | — | CAR-5, CAR-6, WFL-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CMP-2`, `CMP-4`, `DES-6`, `LMS-5`, `POS-1`, `WFL-8`
