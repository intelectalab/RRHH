# POS · Posiciones y headcount

Plazas presupuestadas, solicitud y aprobación de posiciones, presupuesto, escenarios y organigrama por posiciones.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 7 (5 historias y 2 tareas); 7 del MVP. **Operaciones de API:** 47.
- **Especificación:** `kit/docs/modulos/M-POS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica POS · Posiciones y headcount de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-POS.md, kit/prompts/POS.md y la sección POS de
kit/backlog/backlog.md (grep -n "^### POS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. POS-1 · Posiciones como plazas
2. POS-2 · Estados de la posición
3. POS-7 · Organigrama por posiciones
4. POS-3 · Solicitud y aprobación de nuevas posiciones
5. POS-4 · Presupuesto de dotación y desviaciones
6. POS-6 · Política de contratar solo contra posición
7. POS-5 · Escenarios de planificación
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-05. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `POS-1` | Posiciones como plazas | Historia | MVP | ORG-5, ORG-3 |
| 2 | `POS-2` | Estados de la posición | Tarea | MVP | POS-1, PLT-8 |
| 3 | `POS-7` | Organigrama por posiciones | Historia | MVP | POS-1, ORG-6 |
| 4 | `POS-3` | Solicitud y aprobación de nuevas posiciones | Historia | MVP | POS-2, WFL-2 |
| 5 | `POS-4` | Presupuesto de dotación y desviaciones | Historia | MVP | POS-2, SEG-9 |
| 6 | `POS-6` | Política de contratar solo contra posición | Tarea | MVP | POS-2 |
| 7 | `POS-5` | Escenarios de planificación | Historia | MVP | POS-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ORG-3`, `ORG-5`, `ORG-6`, `PLT-8`, `SEG-9`, `WFL-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-05 | Política «no contratar sin posición aprobada» | Fase 1 | (b), apagada por defecto para organizaciones pequeñas; cuando está apagada, el alta crea la posición de forma implícita para que el organigrama por posiciones siga funcionando. |
