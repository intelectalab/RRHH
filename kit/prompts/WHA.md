# WHA · Autoservicio por WhatsApp

Canal de WhatsApp para boletas, vacaciones, constancias y marcación, sobre la capa de mensajería.

- **Fase:** Fase 9 · Ecosistema y expansión. **Módulo comercial:** WhatsApp.
- **Actividades:** 5 (4 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 3.
- **Especificación:** `kit/docs/modulos/M-WHA.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica WHA · Autoservicio por WhatsApp de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-WHA.md, kit/prompts/WHA.md y la sección WHA de
kit/backlog/backlog.md (grep -n "^### WHA-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. WHA-1 · Vinculación de identidad y sesión en WhatsApp
2. WHA-2 · Menú conversacional y consultas
3. WHA-4 · Solicitud de vacaciones por WhatsApp
4. WHA-5 · Marcación por WhatsApp
5. WHA-3 · Boletas y constancias por WhatsApp
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-11. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `WHA-1` | Vinculación de identidad y sesión en WhatsApp | Tarea | ola 2 | NOT-5, SEG-2 |
| 2 | `WHA-2` | Menú conversacional y consultas | Historia | ola 2 | WHA-1, VAC-2 |
| 3 | `WHA-4` | Solicitud de vacaciones por WhatsApp | Historia | — | WHA-2, VAC-3 |
| 4 | `WHA-5` | Marcación por WhatsApp | Historia | — | WHA-2, ASI-3 |
| 5 | `WHA-3` | Boletas y constancias por WhatsApp | Historia | ola 2 | WHA-2, BOL-2, ESS-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-3`, `BOL-2`, `ESS-4`, `NOT-5`, `SEG-2`, `VAC-2`, `VAC-3`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-11 | Proveedor de WhatsApp y alcance del autoservicio | Segunda ola (notificaciones: fase 0) | notificaciones desde la fase 0 tras el puerto; autoservicio (b) en la fase 9. Elegir proveedor según requisitos de plantilla, alojamiento de datos y condiciones. |
