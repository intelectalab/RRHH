# BOL · Boletas de pago

Boletas en PDF, publicación en el portal con constancia de entrega e historial.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 5 (3 historias y 2 tareas); 5 del MVP. **Operaciones de API:** 17.
- **Especificación:** `kit/docs/modulos/M-BOL.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica BOL · Boletas de pago de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-BOL.md, kit/prompts/BOL.md y la sección BOL de
kit/backlog/backlog.md (grep -n "^### BOL-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. BOL-1 · Generación de boletas en PDF
2. BOL-2 · Publicación en el portal y constancia de entrega
3. BOL-3 · Historial y descarga masiva
4. BOL-4 · Conservación de boletas y constancias
5. BOL-5 · Explicación del cambio de neto en la boleta
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
| 1 | `BOL-1` | Generación de boletas en PDF | Tarea | MVP | PER-8, DOC-5 |
| 2 | `BOL-2` | Publicación en el portal y constancia de entrega | Historia | MVP | BOL-1, ESS-3, DOC-7 |
| 3 | `BOL-3` | Historial y descarga masiva | Historia | MVP | BOL-2, SEG-12 |
| 4 | `BOL-4` | Conservación de boletas y constancias | Tarea | MVP | BOL-2 |
| 5 | `BOL-5` | Explicación del cambio de neto en la boleta | Historia | MVP | BOL-2, NOM-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`DOC-5`, `DOC-7`, `ESS-3`, `NOM-6`, `PER-8`, `SEG-12`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-11 | Generación de PDF | Fase 1 | (a) tras el puerto `PdfRenderer`: misma maquetación que la vista previa y buen soporte tipográfico. El servicio corre sin red y sin acceso a datos. |
