# ONB · Pre-onboarding y onboarding

Recolección previa de datos y documentos, diseñador de procesos de ingreso y seguimiento.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Onboarding y offboarding.
- **Actividades:** 6 (6 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 12.
- **Especificación:** `kit/docs/modulos/M-ONB.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ONB · Pre-onboarding y onboarding de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ONB.md, kit/prompts/ONB.md y la sección ONB de
kit/backlog/backlog.md (grep -n "^### ONB-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ONB-3 · Diseñador de plantillas de onboarding
2. ONB-1 · Portal de pre-onboarding
3. ONB-2 · Validación y alta desde el pre-onboarding
4. ONB-4 · Ejecución del proceso de onboarding
5. ONB-5 · Avance y seguimiento
6. ONB-6 · Evaluación del periodo de prueba
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-17. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ONB-3` | Diseñador de plantillas de onboarding | Historia | — | WFL-8, WFL-5 |
| 2 | `ONB-1` | Portal de pre-onboarding | Historia | — | ENT-6, DOC-3 |
| 3 | `ONB-2` | Validación y alta desde el pre-onboarding | Historia | — | ONB-1, CON-2 |
| 4 | `ONB-4` | Ejecución del proceso de onboarding | Historia | — | ONB-3, ONB-2 |
| 5 | `ONB-5` | Avance y seguimiento | Historia | — | ONB-4 |
| 6 | `ONB-6` | Evaluación del periodo de prueba | Historia | — | ONB-5, CON-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CON-2`, `DOC-3`, `ENT-6`, `WFL-5`, `WFL-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-17 | Eventos públicos: `employee.created` frente a `employee.hired` | Fase 1 | (c), porque el pedido original nombra ambos y las integraciones suelen esperar `employee.created`. Si se necesita reaccionar al pre-ingreso, usar `offer.accepted`. |
