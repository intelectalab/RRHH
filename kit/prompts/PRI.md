# PRI · Privacidad y retención

Consentimientos, derechos del titular, banco de datos, incidentes, evaluación de impacto y retención.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Cumplimiento Perú.
- **Actividades:** 7 (6 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 22.
- **Especificación:** `kit/docs/modulos/M-PRI.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PRI · Privacidad y retención de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PRI.md, kit/prompts/PRI.md y la sección PRI de
kit/backlog/backlog.md (grep -n "^### PRI-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PRI-7 · Registro de accesos y descargas de datos personales
2. PRI-1 · Consentimientos y finalidades
3. PRI-2 · Solicitudes de derechos del titular
4. PRI-3 · Banco de datos y registro de tratamientos
5. PRI-6 · Políticas de retención, anonimización y eliminación
6. PRI-4 · Registro de incidentes de seguridad
7. PRI-5 · Evaluación de impacto
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-14. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `PRI-7` | Registro de accesos y descargas de datos personales | Historia | — | SEG-11, AUD-3 |
| 2 | `PRI-1` | Consentimientos y finalidades | Historia | — | COL-1, DOC-7 |
| 3 | `PRI-2` | Solicitudes de derechos del titular | Historia | — | PRI-1, WFL-8 |
| 4 | `PRI-3` | Banco de datos y registro de tratamientos | Historia | — | PRI-1 |
| 5 | `PRI-6` | Políticas de retención, anonimización y eliminación | Tarea | — | PRI-1, CFG-9 |
| 6 | `PRI-4` | Registro de incidentes de seguridad | Historia | — | PRI-3 |
| 7 | `PRI-5` | Evaluación de impacto | Historia | — | PRI-3, ASI-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-3`, `AUD-3`, `CFG-9`, `COL-1`, `DOC-7`, `SEG-11`, `WFL-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-14 | Privacidad: qué va en la base y qué en el módulo de cumplimiento | Fase 1 | (c). Base: avisos, consentimientos, derechos del titular, registro de accesos, retención. Módulo: inventario de tratamientos, evaluaciones de impacto, registro de incidentes con seguimiento, informes. |
