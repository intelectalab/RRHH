# GAS · Gastos, viáticos y reembolsos

Registro de gastos con comprobante, políticas por puesto, viáticos, rendiciones y reembolso.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Gastos y viáticos.
- **Actividades:** 6 (6 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 20.
- **Especificación:** `kit/docs/modulos/M-GAS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica GAS · Gastos, viáticos y reembolsos de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-GAS.md, kit/prompts/GAS.md y la sección GAS de
kit/backlog/backlog.md (grep -n "^### GAS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. GAS-1 · Registro de gastos
2. GAS-2 · Categorías y políticas por puesto
3. GAS-3 · Aprobación de gastos
4. GAS-4 · Solicitud de viáticos y anticipo
5. GAS-5 · Rendición de viáticos
6. GAS-6 · Reembolso y contabilización
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
| 1 | `GAS-1` | Registro de gastos | Historia | — | ESS-1, ORG-4, CFG-7 |
| 2 | `GAS-2` | Categorías y políticas por puesto | Historia | — | GAS-1, HIS-6 |
| 3 | `GAS-3` | Aprobación de gastos | Historia | — | GAS-2, WFL-2 |
| 4 | `GAS-4` | Solicitud de viáticos y anticipo | Historia | — | GAS-3 |
| 5 | `GAS-5` | Rendición de viáticos | Historia | — | GAS-4, PRE-3 |
| 6 | `GAS-6` | Reembolso y contabilización | Historia | — | GAS-3, PRV-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-7`, `ESS-1`, `HIS-6`, `ORG-4`, `PRE-3`, `PRV-3`, `WFL-2`
