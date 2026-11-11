# AUD · Auditoría

Auditoría de negocio de solo inserción con antes y después, separada de la actividad de usuario y de los logs técnicos.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 5 (1 historias y 4 tareas); 5 del MVP. **Operaciones de API:** 11.
- **Especificación:** `kit/docs/modulos/M-AUD.md`.
- **Ya avanzado en el kit:** Auditoría particionada por mes y de solo inserción en `schema.sql`, con su prueba en `pruebas_aislamiento.sql`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica AUD · Auditoría de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-AUD.md, kit/prompts/AUD.md y la sección AUD de
kit/backlog/backlog.md (grep -n "^### AUD-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. AUD-1 · Bitácora de auditoría de solo inserción
2. AUD-3 · Actividad de usuario y logs técnicos separados
3. AUD-5 · Integridad y retención de la auditoría
4. AUD-2 · Captura automática de antes y después
5. AUD-4 · Visor de auditoría
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
| 1 | `AUD-1` | Bitácora de auditoría de solo inserción | Tarea | MVP | TEN-2, PLT-12 |
| 2 | `AUD-3` | Actividad de usuario y logs técnicos separados | Tarea | MVP | AUD-1, PLT-10 |
| 3 | `AUD-5` | Integridad y retención de la auditoría | Tarea | MVP | AUD-1 |
| 4 | `AUD-2` | Captura automática de antes y después | Tarea | MVP | AUD-1, SEG-9 |
| 5 | `AUD-4` | Visor de auditoría | Historia | MVP | AUD-2, UI-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`PLT-10`, `PLT-12`, `SEG-9`, `TEN-2`, `UI-4`
