# SST · Seguridad y salud en el trabajo

Accidentes, riesgos, inspecciones, salud ocupacional, comité y registros obligatorios.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Seguridad y salud.
- **Actividades:** 11 (10 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 54.
- **Especificación:** `kit/docs/modulos/M-SST.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica SST · Seguridad y salud en el trabajo de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-SST.md, kit/prompts/SST.md y la sección SST de
kit/backlog/backlog.md (grep -n "^### SST-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. SST-7 · Comité o supervisor de SST
2. SST-2 · Matriz de riesgos IPERC
3. SST-10 · Proceso electoral del comité de SST
4. SST-3 · Inspecciones y observaciones
5. SST-5 · Exámenes médicos ocupacionales
6. SST-1 · Accidentes, incidentes y enfermedades ocupacionales
7. SST-4 · Medidas correctivas
8. SST-6 · Capacitaciones y simulacros de SST
9. SST-8 · Registros obligatorios y plazos de conservación
10. SST-9 · Tablero y estadísticas de SST
11. SST-11 · Sesiones, quórum y libro de actas
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-15, D-33. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `SST-7` | Comité o supervisor de SST | Historia | — | ORG-1, WFL-8 |
| 2 | `SST-2` | Matriz de riesgos IPERC | Historia | — | ORG-2, ORG-5 |
| 3 | `SST-10` | Proceso electoral del comité de SST | Historia | — | SST-7, CUM-8 |
| 4 | `SST-3` | Inspecciones y observaciones | Historia | — | SST-2, UI-9 |
| 5 | `SST-5` | Exámenes médicos ocupacionales | Historia | — | SST-2, SEG-11 |
| 6 | `SST-1` | Accidentes, incidentes y enfermedades ocupacionales | Historia | — | COL-3, PLT-7 |
| 7 | `SST-4` | Medidas correctivas | Historia | — | SST-1, SST-3, WFL-8 |
| 8 | `SST-6` | Capacitaciones y simulacros de SST | Historia | — | SST-1, LMS-5 |
| 9 | `SST-8` | Registros obligatorios y plazos de conservación | Tarea | — | SST-1, SST-3, SST-5, SST-6 |
| 10 | `SST-9` | Tablero y estadísticas de SST | Historia | — | SST-4, SST-8 |
| 11 | `SST-11` | Sesiones, quórum y libro de actas | Historia | — | SST-7, CUM-8, DOC-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-3`, `CUM-8`, `DOC-6`, `LMS-5`, `ORG-1`, `ORG-2`, `ORG-5`, `PLT-7`, `SEG-11`, `UI-9`, `WFL-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-15 | Validación legal de plazos, retenciones y rol de encargado | Fase 1 (y antes de cada fase con norma) | (a). Cada valor entra como parámetro con estado «por validar» y no puede usarse en producción hasta que lo valide el responsable (D-27). |
| D-33 | Comités por razón social o por centro de trabajo, y paridad | Fase 7 | (a), con prueba automática. Confirmar con el especialista si el umbral de hostigamiento se evalúa por centro de trabajo o por empresa cuando hay varios centros pequeños. |
