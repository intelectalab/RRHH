# API · API pública, webhooks y MCP

API pública versionada con tokens y scopes, webhooks firmados y servidor MCP para asistentes externos.

- **Fase:** Fase 9 · Ecosistema y expansión. **Módulo comercial:** API e integraciones.
- **Actividades:** 5 (3 historias y 2 tareas); 0 del MVP. **Operaciones de API:** 14.
- **Especificación:** `kit/docs/modulos/M-API.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica API · API pública, webhooks y MCP de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-API.md, kit/prompts/API.md y la sección API de
kit/backlog/backlog.md (grep -n "^### API-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. API-1 · API pública con tokens y scopes
2. API-2 · Portal de desarrolladores
3. API-3 · Límites de uso y versionado
4. API-4 · Webhooks
5. API-5 · Servidor MCP
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-17, D-18, D-19, D-21. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `API-1` | API pública con tokens y scopes | Historia | — | PLT-5, SEG-8, SAS-5 |
| 2 | `API-2` | Portal de desarrolladores | Historia | — | API-1 |
| 3 | `API-3` | Límites de uso y versionado | Tarea | — | API-1, SAS-6 |
| 4 | `API-4` | Webhooks | Historia | — | API-1, PLT-6 |
| 5 | `API-5` | Servidor MCP | Tarea | — | API-1, IAR-2, IAR-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`IAR-2`, `IAR-8`, `PLT-5`, `PLT-6`, `SAS-5`, `SAS-6`, `SEG-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-17 | Eventos públicos: `employee.created` frente a `employee.hired` | Fase 1 | (c), porque el pedido original nombra ambos y las integraciones suelen esperar `employee.created`. Si se necesita reaccionar al pre-ingreso, usar `offer.accepted`. |
| D-18 | Política de versiones y retirada de la API | Fase 9 | publicar la política antes de abrir la API a terceros; avisar con cabeceras `Deprecation` y `Sunset` y por correo a los dueños de tokens. |
| D-19 | Límites de uso de API por plan | Fase 9 | por plan, como parámetros de plataforma, con límites más estrictos en rutas públicas, autenticación e IA. |
| D-21 | Herramientas del servidor MCP | Fase 9 | (b), lista cerrada y versionada; nada de alto impacto. |
