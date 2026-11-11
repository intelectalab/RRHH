# ESS · Portal del colaborador

Autoservicio del colaborador: inicio personalizado, perfil, documentos, firma, constancias y solicitudes.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 6 (5 historias y 1 tareas); 6 del MVP. **Operaciones de API:** 8.
- **Especificación:** `kit/docs/modulos/M-ESS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ESS · Portal del colaborador de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ESS.md, kit/prompts/ESS.md y la sección ESS de
kit/backlog/backlog.md (grep -n "^### ESS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ESS-1 · Inicio del colaborador
2. ESS-2 · Mi perfil y actualización de datos
3. ESS-5 · Solicitudes a RRHH desde el portal
4. ESS-6 · Portal del colaborador en el móvil
5. ESS-4 · Solicitud de certificados y constancias
6. ESS-3 · Mis documentos, contrato y firma
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-12, D-23. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ESS-1` | Inicio del colaborador | Historia | MVP | WFL-9, COL-3 |
| 2 | `ESS-2` | Mi perfil y actualización de datos | Historia | MVP | COL-4, WFL-2 |
| 3 | `ESS-5` | Solicitudes a RRHH desde el portal | Historia | MVP | ESS-1, WFL-2 |
| 4 | `ESS-6` | Portal del colaborador en el móvil | Tarea | MVP | ESS-1, UI-9, SEG-2 |
| 5 | `ESS-4` | Solicitud de certificados y constancias | Historia | MVP | DOC-5, ESS-1 |
| 6 | `ESS-3` | Mis documentos, contrato y firma | Historia | MVP | DOC-6, DOC-1 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-3`, `COL-4`, `DOC-1`, `DOC-5`, `DOC-6`, `SEG-2`, `UI-9`, `WFL-2`, `WFL-9`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-12 | App móvil: web instalable o nativa | Fase 2 | (c). La API ya lo permite; la PWA cubre los flujos del colaborador. |
| D-23 | Acceso del colaborador tras el cese | Fase 1 | (b), con duración configurable por organización; no cuenta para el límite del plan. |
