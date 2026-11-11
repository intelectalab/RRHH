# DEN · Canal de denuncias y hostigamiento

Canal confidencial independiente, anonimato real, investigación restringida y procedimiento de hostigamiento sexual.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Denuncias.
- **Actividades:** 8 (7 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 20.
- **Especificación:** `kit/docs/modulos/M-DEN.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica DEN · Canal de denuncias y hostigamiento de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-DEN.md, kit/prompts/DEN.md y la sección DEN de
kit/backlog/backlog.md (grep -n "^### DEN-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. DEN-1 · Canal público de denuncias
2. DEN-2 · Anonimato sin rastro técnico
3. DEN-3 · Código de seguimiento y comunicación con el denunciante
4. DEN-4 · Gestión del caso
5. DEN-5 · Investigadores y acceso restringido
6. DEN-6 · Procedimiento de hostigamiento sexual
7. DEN-8 · Comunicaciones a la autoridad y expediente del procedimiento
8. DEN-7 · Comité de intervención y capacitación obligatoria
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-26, D-31, D-32. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `DEN-1` | Canal público de denuncias | Historia | — | PLT-7, TEN-1 |
| 2 | `DEN-2` | Anonimato sin rastro técnico | Tarea | — | DEN-1, PLT-10 |
| 3 | `DEN-3` | Código de seguimiento y comunicación con el denunciante | Historia | — | DEN-2 |
| 4 | `DEN-4` | Gestión del caso | Historia | — | DEN-3, PLT-8 |
| 5 | `DEN-5` | Investigadores y acceso restringido | Historia | — | DEN-4, SEG-11 |
| 6 | `DEN-6` | Procedimiento de hostigamiento sexual | Historia | — | DEN-5, CFG-3 |
| 7 | `DEN-8` | Comunicaciones a la autoridad y expediente del procedimiento | Historia | — | DEN-6, CUM-8 |
| 8 | `DEN-7` | Comité de intervención y capacitación obligatoria | Historia | — | DEN-6, ORG-1 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-3`, `CUM-8`, `ORG-1`, `PLT-7`, `PLT-8`, `PLT-10`, `SEG-11`, `TEN-1`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-26 | Anonimato: umbral mínimo y denuncias anónimas | Fase 6 (encuestas) y fase 7 (denuncias) | umbral como parámetro con un mínimo que la organización no puede bajar; denuncias anónimas admitidas, con aviso al denunciante de sus límites. Validar con legal (D-15). |
| D-31 | Hora en los plazos y en el libro de una denuncia | Fase 7 | (a). Los plazos del reglamento están en días, no en horas. `compliance_deadlines` conserva sus marcas de tiempo técnicas: definir si para sujetos «denuncia» también deben truncarse. |
| D-32 | Bitácora del caso de denuncia: `complaint_events` frente al libro encadenado | Fase 7 | (b) al implementar DEN, para no tener dos bitácoras. Hasta entonces rige (a). |
