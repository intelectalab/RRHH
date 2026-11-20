# CON · Contratos y adendas

Contratos por modalidad con vigencia, renovaciones, adendas, alertas de vencimiento y de tope acumulado.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 6 (5 historias y 1 tareas); 6 del MVP. **Operaciones de API:** 36.
- **Especificación:** `kit/docs/modulos/M-CON.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CON · Contratos y adendas de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CON.md, kit/prompts/CON-epica.md y la sección CON de
kit/backlog/backlog.md (grep -n "^### CON-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CON-1 · Tipos de contrato y modalidades
2. CON-2 · Registro del contrato
3. CON-3 · Renovaciones y adendas
4. CON-4 · Alertas de vencimiento y de tope acumulado
5. CON-6 · Acuerdo de teletrabajo
6. CON-5 · Renovación masiva con firma
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
| 1 | `CON-1` | Tipos de contrato y modalidades | Tarea | MVP | CFG-3 |
| 2 | `CON-2` | Registro del contrato | Historia | MVP | CON-1, COL-2, HIS-1 |
| 3 | `CON-3` | Renovaciones y adendas | Historia | MVP | CON-2 |
| 4 | `CON-4` | Alertas de vencimiento y de tope acumulado | Historia | MVP | CON-3, NOT-1 |
| 5 | `CON-6` | Acuerdo de teletrabajo | Historia | MVP | CON-3, DOC-4 |
| 6 | `CON-5` | Renovación masiva con firma | Historia | MVP | CON-4, DOC-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-3`, `COL-2`, `DOC-4`, `DOC-6`, `HIS-1`, `NOT-1`
