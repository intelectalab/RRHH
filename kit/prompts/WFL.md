# WFL · Motor de aprobaciones y workflows

Motor transversal de aprobaciones, reglas CUANDO → SI → ENTONCES, constructor visual, tareas y bandejas.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 9 (5 historias y 4 tareas); 9 del MVP. **Operaciones de API:** 32.
- **Especificación:** `kit/docs/modulos/M-WFL.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica WFL · Motor de aprobaciones y workflows de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-WFL.md, kit/prompts/WFL.md y la sección WFL de
kit/backlog/backlog.md (grep -n "^### WFL-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. WFL-1 · Modelo de definiciones, versiones y ejecuciones
2. WFL-2 · Motor de aprobaciones de N niveles
3. WFL-5 · Reglas CUANDO → SI → ENTONCES
4. WFL-3 · Resolución de aprobadores, delegación y escalamiento
5. WFL-4 · Centro de aprobaciones
6. WFL-6 · Constructor visual de flujos
7. WFL-7 · Centro de automatizaciones
8. WFL-8 · Tareas de RRHH
9. WFL-9 · Bandeja de pendientes
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
| 1 | `WFL-1` | Modelo de definiciones, versiones y ejecuciones | Tarea | MVP | PLT-6, PLT-8 |
| 2 | `WFL-2` | Motor de aprobaciones de N niveles | Tarea | MVP | WFL-1 |
| 3 | `WFL-5` | Reglas CUANDO → SI → ENTONCES | Tarea | MVP | WFL-1 |
| 4 | `WFL-3` | Resolución de aprobadores, delegación y escalamiento | Tarea | MVP | WFL-2 |
| 5 | `WFL-4` | Centro de aprobaciones | Historia | MVP | WFL-2, UI-4 |
| 6 | `WFL-6` | Constructor visual de flujos | Historia | MVP | WFL-5, WFL-2, UI-12 |
| 7 | `WFL-7` | Centro de automatizaciones | Historia | MVP | WFL-5 |
| 8 | `WFL-8` | Tareas de RRHH | Historia | MVP | WFL-1, UI-4 |
| 9 | `WFL-9` | Bandeja de pendientes | Historia | MVP | WFL-4, WFL-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`PLT-6`, `PLT-8`, `UI-4`, `UI-12`
