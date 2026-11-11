# BNF · Beneficios

Catálogo de beneficios con elegibilidad, inscripción y consulta por el colaborador.

- **Fase:** Fase 5 · Desarrollo y compensación. **Módulo comercial:** Beneficios.
- **Actividades:** 5 (4 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 11.
- **Especificación:** `kit/docs/modulos/M-BNF.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica BNF · Beneficios de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-BNF.md, kit/prompts/BNF.md y la sección BNF de
kit/backlog/backlog.md (grep -n "^### BNF-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. BNF-1 · Catálogo de beneficios
2. BNF-2 · Reglas de elegibilidad
3. BNF-3 · Inscripción y dependientes
4. BNF-4 · Mis beneficios
5. BNF-5 · Beneficios con efecto en planilla
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
| 1 | `BNF-1` | Catálogo de beneficios | Historia | — | CFG-7 |
| 2 | `BNF-2` | Reglas de elegibilidad | Historia | — | BNF-1, HIS-6 |
| 3 | `BNF-3` | Inscripción y dependientes | Historia | — | BNF-2, COL-6 |
| 4 | `BNF-4` | Mis beneficios | Historia | — | BNF-3, ESS-1 |
| 5 | `BNF-5` | Beneficios con efecto en planilla | Tarea | — | BNF-3, PER-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-7`, `COL-6`, `ESS-1`, `HIS-6`, `PER-2`
