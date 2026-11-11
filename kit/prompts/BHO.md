# BHO · Horas extra y bolsa de horas

Autorización y cálculo de sobretiempo, y bolsa de horas con saldos para compensar con descanso.

- **Fase:** Fase 2 · Tiempo. **Módulo comercial:** Asistencia y turnos.
- **Actividades:** 5 (3 historias y 2 tareas); 0 del MVP. **Operaciones de API:** 8.
- **Especificación:** `kit/docs/modulos/M-BHO.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica BHO · Horas extra y bolsa de horas de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-BHO.md, kit/prompts/BHO.md y la sección BHO de
kit/backlog/backlog.md (grep -n "^### BHO-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. BHO-1 · Solicitud y autorización de horas extra
2. BHO-2 · Cálculo de sobretiempo
3. BHO-3 · Bolsa de horas con saldos
4. BHO-4 · Compensación con descanso
5. BHO-5 · Vencimiento de saldos y reporte
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
| 1 | `BHO-1` | Solicitud y autorización de horas extra | Historia | — | ASI-8, WFL-2 |
| 2 | `BHO-2` | Cálculo de sobretiempo | Tarea | — | BHO-1, TUR-2 |
| 3 | `BHO-3` | Bolsa de horas con saldos | Tarea | — | BHO-2 |
| 4 | `BHO-4` | Compensación con descanso | Historia | — | BHO-3, AUS-2 |
| 5 | `BHO-5` | Vencimiento de saldos y reporte | Historia | — | BHO-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-8`, `AUS-2`, `TUR-2`, `WFL-2`
