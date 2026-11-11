# HIS · Historia con vigencia

Registros con vigencia para asignación, compensación, contrato y jefe; nada se sobrescribe.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 6 (3 historias y 3 tareas); 6 del MVP. **Operaciones de API:** 9.
- **Especificación:** `kit/docs/modulos/M-HIS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica HIS · Historia con vigencia de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-HIS.md, kit/prompts/HIS.md y la sección HIS de
kit/backlog/backlog.md (grep -n "^### HIS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. HIS-1 · Patrón de vigencia reutilizable
2. HIS-3 · Compensación con vigencia
3. HIS-2 · Movimientos de personal
4. HIS-4 · Cambios con fecha futura y correcciones retroactivas
5. HIS-5 · Línea de tiempo laboral
6. HIS-6 · Servicio de consulta de datos maestros
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
| 1 | `HIS-1` | Patrón de vigencia reutilizable | Tarea | MVP | PLT-12 |
| 2 | `HIS-3` | Compensación con vigencia | Historia | MVP | HIS-1, SEG-9 |
| 3 | `HIS-2` | Movimientos de personal | Historia | MVP | HIS-1, COL-2, WFL-2 |
| 4 | `HIS-4` | Cambios con fecha futura y correcciones retroactivas | Tarea | MVP | HIS-2, HIS-3, PLT-6 |
| 5 | `HIS-5` | Línea de tiempo laboral | Historia | MVP | HIS-2, HIS-3, UI-3 |
| 6 | `HIS-6` | Servicio de consulta de datos maestros | Tarea | MVP | HIS-2, HIS-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-2`, `PLT-6`, `PLT-12`, `SEG-9`, `UI-3`, `WFL-2`
