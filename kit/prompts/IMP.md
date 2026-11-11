# IMP · Importador y puesta en marcha

Importación masiva con validación, asistente de primeros pasos, plantillas peruanas y organización demo.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 7 (4 historias y 3 tareas); 7 del MVP. **Operaciones de API:** 8.
- **Especificación:** `kit/docs/modulos/M-IMP.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica IMP · Importador y puesta en marcha de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-IMP.md, kit/prompts/IMP.md y la sección IMP de
kit/backlog/backlog.md (grep -n "^### IMP-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. IMP-1 · Motor de importación
2. IMP-6 · Informe y reversión de importaciones
3. IMP-2 · Importadores de Core HR
4. IMP-3 · Asistente de primeros pasos
5. IMP-5 · Organización demo coherente
6. IMP-7 · Importadores desde otros sistemas de RRHH y planilla
7. IMP-4 · Plantillas peruanas listas para usar
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
| 1 | `IMP-1` | Motor de importación | Tarea | MVP | PLT-6, PLT-7 |
| 2 | `IMP-6` | Informe y reversión de importaciones | Historia | MVP | IMP-1 |
| 3 | `IMP-2` | Importadores de Core HR | Historia | MVP | IMP-1, COL-2, HIS-2, CON-2 |
| 4 | `IMP-3` | Asistente de primeros pasos | Historia | MVP | IMP-2, CFG-1 |
| 5 | `IMP-5` | Organización demo coherente | Tarea | MVP | IMP-2 |
| 6 | `IMP-7` | Importadores desde otros sistemas de RRHH y planilla | Historia | MVP | IMP-2, IMP-6 |
| 7 | `IMP-4` | Plantillas peruanas listas para usar | Tarea | MVP | CON-1, DOC-4, CFG-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-1`, `CFG-8`, `COL-2`, `CON-1`, `CON-2`, `DOC-4`, `HIS-2`, `PLT-6`, `PLT-7`
