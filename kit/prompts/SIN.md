# SIN · Sindicatos y convenios

Afiliación sindical, cuota, licencias sindicales y cláusulas de convenio como conceptos.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Cumplimiento Perú.
- **Actividades:** 4 (3 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 14.
- **Especificación:** `kit/docs/modulos/M-SIN.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica SIN · Sindicatos y convenios de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-SIN.md, kit/prompts/SIN.md y la sección SIN de
kit/backlog/backlog.md (grep -n "^### SIN-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. SIN-1 · Sindicatos y afiliación
2. SIN-3 · Licencias sindicales
3. SIN-4 · Convenios colectivos y cláusulas
4. SIN-2 · Cuota sindical en planilla
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-33. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `SIN-1` | Sindicatos y afiliación | Historia | — | COL-3, SEG-9 |
| 2 | `SIN-3` | Licencias sindicales | Historia | — | SIN-1, AUS-1 |
| 3 | `SIN-4` | Convenios colectivos y cláusulas | Historia | — | SIN-1, CPT-1 |
| 4 | `SIN-2` | Cuota sindical en planilla | Tarea | — | SIN-1, PER-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUS-1`, `COL-3`, `CPT-1`, `PER-2`, `SEG-9`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-33 | Comités por razón social o por centro de trabajo, y paridad | Fase 7 | (a), con prueba automática. Confirmar con el especialista si el umbral de hostigamiento se evalúa por centro de trabajo o por empresa cuando hay varios centros pequeños. |
