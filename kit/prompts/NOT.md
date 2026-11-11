# NOT · Notificaciones y mensajería

Motor de notificaciones con plantillas y canales (en la aplicación, correo, push y mensajería) y preferencias por usuario.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 6 (2 historias y 4 tareas); 6 del MVP. **Operaciones de API:** 10.
- **Especificación:** `kit/docs/modulos/M-NOT.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica NOT · Notificaciones y mensajería de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-NOT.md, kit/prompts/NOT.md y la sección NOT de
kit/backlog/backlog.md (grep -n "^### NOT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. NOT-1 · Motor de notificaciones y plantillas
2. NOT-3 · Canal de correo
3. NOT-5 · Capa de mensajería para SMS y WhatsApp
4. NOT-2 · Centro de notificaciones en la aplicación
5. NOT-4 · Canal push
6. NOT-6 · Preferencias de notificación
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
| 1 | `NOT-1` | Motor de notificaciones y plantillas | Tarea | MVP | PLT-6, PLT-13 |
| 2 | `NOT-3` | Canal de correo | Tarea | MVP | NOT-1 |
| 3 | `NOT-5` | Capa de mensajería para SMS y WhatsApp | Tarea | MVP | NOT-1 |
| 4 | `NOT-2` | Centro de notificaciones en la aplicación | Historia | MVP | NOT-1, UI-2 |
| 5 | `NOT-4` | Canal push | Tarea | MVP | NOT-1, UI-9 |
| 6 | `NOT-6` | Preferencias de notificación | Historia | MVP | NOT-2, NOT-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`PLT-6`, `PLT-13`, `UI-2`, `UI-9`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-11 | Proveedor de WhatsApp y alcance del autoservicio | Segunda ola (notificaciones: fase 0) | notificaciones desde la fase 0 tras el puerto; autoservicio (b) en la fase 9. Elegir proveedor según requisitos de plantilla, alojamiento de datos y condiciones. |
