# ACT · Activos, EPP y uniformes

Inventario y asignación de activos, y entrega de equipos de protección y uniformes con firma.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Activos y EPP.
- **Actividades:** 6 (6 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 13.
- **Especificación:** `kit/docs/modulos/M-ACT.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ACT · Activos, EPP y uniformes de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ACT.md, kit/prompts/ACT.md y la sección ACT de
kit/backlog/backlog.md (grep -n "^### ACT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ACT-1 · Inventario de activos
2. ACT-4 · Matriz de EPP por puesto
3. ACT-2 · Asignación y devolución con acta firmada
4. ACT-3 · Historial y activos en ingresos y salidas
5. ACT-5 · Entrega de EPP con firma y vencimientos
6. ACT-6 · Uniformes y tallas
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
| 1 | `ACT-1` | Inventario de activos | Historia | — | ORG-2 |
| 2 | `ACT-4` | Matriz de EPP por puesto | Historia | — | SST-2, ACT-1 |
| 3 | `ACT-2` | Asignación y devolución con acta firmada | Historia | — | ACT-1, DOC-6 |
| 4 | `ACT-3` | Historial y activos en ingresos y salidas | Historia | — | ACT-2, OFF-2 |
| 5 | `ACT-5` | Entrega de EPP con firma y vencimientos | Historia | — | ACT-4, DOC-7 |
| 6 | `ACT-6` | Uniformes y tallas | Historia | — | ACT-5 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`DOC-6`, `DOC-7`, `OFF-2`, `ORG-2`, `SST-2`
