# AYU · Centro de ayuda

Ayuda disponible en todos los roles y pantallas: guías del producto, ayuda contextual, políticas de la propia organización, buscador y derivación a RRHH o a soporte.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 7 (6 historias y 1 tareas); 7 del MVP. **Operaciones de API:** 24.
- **Especificación:** `kit/docs/modulos/M-AYU.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica AYU · Centro de ayuda de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-AYU.md, kit/prompts/AYU.md y la sección AYU de
kit/backlog/backlog.md (grep -n "^### AYU-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. AYU-1 · Contenido de ayuda del producto con versiones
2. AYU-2 · Centro de ayuda para todos los roles
3. AYU-3 · Ayuda contextual por pantalla
4. AYU-4 · Artículos y políticas de la organización
5. AYU-7 · Recorridos guiados y novedades de versión
6. AYU-5 · Buscador de ayuda y valoración de artículos
7. AYU-6 · Derivar la consulta a RRHH o a soporte
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-05, A-09, D-38. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `AYU-1` | Contenido de ayuda del producto con versiones | Tarea | MVP | SAS-1, SAS-5, CFG-6 |
| 2 | `AYU-2` | Centro de ayuda para todos los roles | Historia | MVP | AYU-1, UI-2, SEG-7, SAS-5 |
| 3 | `AYU-3` | Ayuda contextual por pantalla | Historia | MVP | AYU-2, UI-6 |
| 4 | `AYU-4` | Artículos y políticas de la organización | Historia | MVP | AYU-2, SEG-10, NOT-2 |
| 5 | `AYU-7` | Recorridos guiados y novedades de versión | Historia | MVP | AYU-2, UI-2 |
| 6 | `AYU-5` | Buscador de ayuda y valoración de artículos | Historia | MVP | AYU-2, AYU-4 |
| 7 | `AYU-6` | Derivar la consulta a RRHH o a soporte | Historia | MVP | AYU-5, ESS-5, SAS-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-6`, `ESS-5`, `NOT-2`, `SAS-1`, `SAS-5`, `SAS-8`, `SEG-7`, `SEG-10`, `UI-2`, `UI-6`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-05 | Motor de búsqueda | Fase 1 (revisable en fase 8) | (a) al inicio, detrás de `SearchProvider`; pasar a (b) solo si las pruebas de carga lo exigen. |
| A-09 | Proveedor de IA y condiciones de datos | Fase 8 | (a) al inicio, tras el puerto `AiProvider`, con condiciones contractuales de no entrenamiento y retención mínima; habilitar (b) para planes altos. |
| D-38 | Asistente de ayuda: estado por defecto, proveedor y forma de búsqueda | Fase 1 (MVP) | (a) y (c). Apagado por defecto cumple RN-IAR-05 y evita enviar preguntas a un proveedor sin que la organización lo decida; el texto completo basta para cientos de artículos y no agrega infraestructura. Pasar a (d) solo si IAR-11 muestra muchas preguntas sin fuente que sí tenían artículo. Propuesta en [ADR 0003](adr/0003-asistente-de-ayuda.md). |
