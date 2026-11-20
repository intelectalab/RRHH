# DOC · Gestión documental, plantillas y firma

Repositorio de documentos con permisos, plantillas dinámicas, generación de PDF y firma electrónica con evidencia.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 8 (5 historias y 3 tareas); 8 del MVP. **Operaciones de API:** 53.
- **Especificación:** `kit/docs/modulos/M-DOC.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica DOC · Gestión documental, plantillas y firma de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-DOC.md, kit/prompts/DOC.md y la sección DOC de
kit/backlog/backlog.md (grep -n "^### DOC-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. DOC-1 · Repositorio y carpetas del legajo
2. DOC-2 · Versiones, archivo y vencimientos
3. DOC-3 · Solicitud y aprobación de documentos
4. DOC-4 · Plantillas dinámicas con variables
5. DOC-5 · Generación de PDF
6. DOC-6 · Flujo de firma
7. DOC-7 · Evidencia de firma electrónica propia
8. DOC-8 · Capa de proveedor de firma digital
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-11. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `DOC-1` | Repositorio y carpetas del legajo | Historia | MVP | PLT-7, SEG-12, COL-3 |
| 2 | `DOC-2` | Versiones, archivo y vencimientos | Historia | MVP | DOC-1 |
| 3 | `DOC-3` | Solicitud y aprobación de documentos | Historia | MVP | DOC-1, WFL-9 |
| 4 | `DOC-4` | Plantillas dinámicas con variables | Historia | MVP | DOC-1 |
| 5 | `DOC-5` | Generación de PDF | Tarea | MVP | DOC-4, PLT-6 |
| 6 | `DOC-6` | Flujo de firma | Historia | MVP | DOC-5, WFL-9, NOT-1 |
| 7 | `DOC-7` | Evidencia de firma electrónica propia | Tarea | MVP | DOC-6, SEG-2 |
| 8 | `DOC-8` | Capa de proveedor de firma digital | Tarea | MVP | DOC-7, PLT-13 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-3`, `NOT-1`, `PLT-6`, `PLT-7`, `PLT-13`, `SEG-2`, `SEG-12`, `WFL-9`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-11 | Generación de PDF | Fase 1 | (a) tras el puerto `PdfRenderer`: misma maquetación que la vista previa y buen soporte tipográfico. El servicio corre sin red y sin acceso a datos. |
