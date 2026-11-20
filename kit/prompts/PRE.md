# PRE · Préstamos, adelantos y adelanto de sueldo

Préstamos con cronograma, adelantos, descuento automático y adelanto de sueldo sobre lo devengado.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Préstamos y adelanto de sueldo.
- **Actividades:** 5 (3 historias y 2 tareas); 0 del MVP. **Operaciones de API:** 15.
- **Especificación:** `kit/docs/modulos/M-PRE.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PRE · Préstamos, adelantos y adelanto de sueldo de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PRE.md, kit/prompts/PRE.md y la sección PRE de
kit/backlog/backlog.md (grep -n "^### PRE-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PRE-1 · Préstamos con cronograma
2. PRE-2 · Adelantos
3. PRE-3 · Descuento automático en planilla
4. PRE-4 · Adelanto de sueldo sobre lo devengado
5. PRE-5 · Pago por billeteras digitales
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
| 1 | `PRE-1` | Préstamos con cronograma | Historia | — | WFL-2, DOC-6 |
| 2 | `PRE-2` | Adelantos | Historia | — | PRE-1 |
| 3 | `PRE-3` | Descuento automático en planilla | Tarea | — | PRE-2, PER-2, LIQ-1 |
| 4 | `PRE-4` | Adelanto de sueldo sobre lo devengado | Historia | — | PRE-3, ESS-5, ASI-8 |
| 5 | `PRE-5` | Pago por billeteras digitales | Tarea | — | PRE-4, PLT-13 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-8`, `DOC-6`, `ESS-5`, `LIQ-1`, `PER-2`, `PLT-13`, `WFL-2`
