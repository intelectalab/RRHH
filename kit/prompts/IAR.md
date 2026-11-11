# IAR · Asistente de IA y gobierno

Capa de IA opcional con registro de uso, permisos del usuario y sin decisiones automáticas de alto impacto.

- **Fase:** Fase 8 · Inteligencia. **Módulo comercial:** Asistente de IA.
- **Actividades:** 12 (7 historias y 5 tareas); 5 del MVP. **Operaciones de API:** 12.
- **Especificación:** `kit/docs/modulos/M-IAR.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica IAR · Asistente de IA y gobierno de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-IAR.md, kit/prompts/IAR.md y la sección IAR de
kit/backlog/backlog.md (grep -n "^### IAR-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. IAR-1 · Capa de proveedor de IA
2. IAR-2 · Registro de uso de IA
3. IAR-3 · La IA respeta los permisos del usuario
4. IAR-8 · Salvaguardas contra decisiones automáticas
5. IAR-4 · Redacción asistida
6. IAR-9 · Asistente de ayuda documental
7. IAR-5 · Resúmenes de CV y de encuestas
8. IAR-10 · Activación del asistente de ayuda desde la administración
9. IAR-11 · Calidad de las respuestas del asistente de ayuda
10. IAR-12 · Asistente de ayuda con los datos del propio usuario
11. IAR-6 · Consulta en lenguaje natural
12. IAR-7 · Explicación de métricas y detección de anomalías
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-09, D-21, D-38. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `IAR-1` | Capa de proveedor de IA | Tarea | MVP | PLT-13, SAS-7 |
| 2 | `IAR-2` | Registro de uso de IA | Tarea | MVP | IAR-1, AUD-3 |
| 3 | `IAR-3` | La IA respeta los permisos del usuario | Tarea | — | IAR-1, SEG-9, SEG-10 |
| 4 | `IAR-8` | Salvaguardas contra decisiones automáticas | Tarea | — | IAR-3 |
| 5 | `IAR-4` | Redacción asistida | Historia | — | IAR-3, ATS-1, CIN-1 |
| 6 | `IAR-9` | Asistente de ayuda documental | Historia | MVP | IAR-1, IAR-2, AYU-5, AYU-6 |
| 7 | `IAR-5` | Resúmenes de CV y de encuestas | Historia | — | IAR-3, ATS-6, ENC-6 |
| 8 | `IAR-10` | Activación del asistente de ayuda desde la administración | Historia | MVP | IAR-9, SAS-7, SEG-7, AUD-2 |
| 9 | `IAR-11` | Calidad de las respuestas del asistente de ayuda | Tarea | MVP | IAR-9, AYU-5 |
| 10 | `IAR-12` | Asistente de ayuda con los datos del propio usuario | Historia | — | IAR-3, IAR-10, VAC-2, BOL-2 |
| 11 | `IAR-6` | Consulta en lenguaje natural | Historia | — | IAR-3, ANA-2, RPT-2 |
| 12 | `IAR-7` | Explicación de métricas y detección de anomalías | Historia | — | IAR-6, ALE-3, PER-7 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ALE-3`, `ANA-2`, `ATS-1`, `ATS-6`, `AUD-2`, `AUD-3`, `AYU-5`, `AYU-6`, `BOL-2`, `CIN-1`, `ENC-6`, `PER-7`, `PLT-13`, `RPT-2`, `SAS-7`, `SEG-7`, `SEG-9`, `SEG-10`, `VAC-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-09 | Proveedor de IA y condiciones de datos | Fase 8 | (a) al inicio, tras el puerto `AiProvider`, con condiciones contractuales de no entrenamiento y retención mínima; habilitar (b) para planes altos. |
| D-21 | Herramientas del servidor MCP | Fase 9 | (b), lista cerrada y versionada; nada de alto impacto. |
| D-38 | Asistente de ayuda: estado por defecto, proveedor y forma de búsqueda | Fase 1 (MVP) | (a) y (c). Apagado por defecto cumple RN-IAR-05 y evita enviar preguntas a un proveedor sin que la organización lo decida; el texto completo basta para cientos de artículos y no agrega infraestructura. Pasar a (d) solo si IAR-11 muestra muchas preguntas sin fuente que sí tenían artículo. Propuesta en [ADR 0003](adr/0003-asistente-de-ayuda.md). |
