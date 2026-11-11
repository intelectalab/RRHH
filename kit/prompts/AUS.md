# AUS · Ausencias y permisos

Tipos de ausencia configurables con reglas propias, descansos médicos, licencias y teletrabajo.

- **Fase:** Fase 2 · Tiempo. **Módulo comercial:** Vacaciones y ausencias.
- **Actividades:** 6 (5 historias y 1 tareas); 6 del MVP. **Operaciones de API:** 14.
- **Especificación:** `kit/docs/modulos/M-AUS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica AUS · Ausencias y permisos de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-AUS.md, kit/prompts/AUS.md y la sección AUS de
kit/backlog/backlog.md (grep -n "^### AUS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. AUS-1 · Tipos de ausencia con reglas propias
2. AUS-4 · Licencias de maternidad, paternidad y otras de ley
3. AUS-2 · Solicitud y registro de ausencias
4. AUS-3 · Descansos médicos y subsidios
5. AUS-5 · Trabajo remoto y desconexión digital
6. AUS-6 · Ausencias como incidencias para planilla
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
| 1 | `AUS-1` | Tipos de ausencia con reglas propias | Historia | MVP | CFG-3, WFL-2 |
| 2 | `AUS-4` | Licencias de maternidad, paternidad y otras de ley | Historia | MVP | AUS-1 |
| 3 | `AUS-2` | Solicitud y registro de ausencias | Historia | MVP | AUS-1, VAC-4 |
| 4 | `AUS-3` | Descansos médicos y subsidios | Historia | MVP | AUS-2, SEG-9 |
| 5 | `AUS-5` | Trabajo remoto y desconexión digital | Historia | MVP | AUS-2, CON-6, NOT-6 |
| 6 | `AUS-6` | Ausencias como incidencias para planilla | Tarea | MVP | AUS-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-3`, `CON-6`, `NOT-6`, `SEG-9`, `VAC-4`, `WFL-2`
