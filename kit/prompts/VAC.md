# VAC · Vacaciones

Saldos, solicitudes con aprobación, programación y calendario del equipo.

- **Fase:** Fase 2 · Tiempo. **Módulo comercial:** Vacaciones y ausencias.
- **Actividades:** 7 (5 historias y 2 tareas); 7 del MVP. **Operaciones de API:** 22.
- **Especificación:** `kit/docs/modulos/M-VAC.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica VAC · Vacaciones de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-VAC.md, kit/prompts/VAC.md y la sección VAC de
kit/backlog/backlog.md (grep -n "^### VAC-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. VAC-1 · Política de vacaciones por régimen
2. VAC-2 · Devengo y saldos de vacaciones
3. VAC-7 · Importación de saldos de vacaciones
4. VAC-3 · Solicitud y aprobación de vacaciones
5. VAC-4 · Calendario de ausencias del equipo
6. VAC-5 · Programación anual y vacaciones acumuladas
7. VAC-6 · Fraccionamiento, adelanto y venta de vacaciones
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
| 1 | `VAC-1` | Política de vacaciones por régimen | Tarea | MVP | CFG-3, ORG-1 |
| 2 | `VAC-2` | Devengo y saldos de vacaciones | Tarea | MVP | VAC-1, COL-2 |
| 3 | `VAC-7` | Importación de saldos de vacaciones | Historia | MVP | VAC-2, IMP-1 |
| 4 | `VAC-3` | Solicitud y aprobación de vacaciones | Historia | MVP | VAC-2, WFL-2, ESS-1 |
| 5 | `VAC-4` | Calendario de ausencias del equipo | Historia | MVP | VAC-3, UI-12, CFG-8 |
| 6 | `VAC-5` | Programación anual y vacaciones acumuladas | Historia | MVP | VAC-3 |
| 7 | `VAC-6` | Fraccionamiento, adelanto y venta de vacaciones | Historia | MVP | VAC-3, DOC-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-3`, `CFG-8`, `COL-2`, `DOC-6`, `ESS-1`, `IMP-1`, `ORG-1`, `UI-12`, `WFL-2`
