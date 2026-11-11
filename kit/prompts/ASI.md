# ASI · Asistencia y marcación

Registro inalterable de marcaciones por múltiples medios, cálculo diario, incidencias y reportes legales.

- **Fase:** Fase 2 · Tiempo. **Módulo comercial:** Asistencia y turnos.
- **Actividades:** 11 (7 historias y 4 tareas); 0 del MVP. **Operaciones de API:** 20.
- **Especificación:** `kit/docs/modulos/M-ASI.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ASI · Asistencia y marcación de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ASI.md, kit/prompts/ASI.md y la sección ASI de
kit/backlog/backlog.md (grep -n "^### ASI-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ASI-1 · Registro de marcaciones inalterable
2. ASI-4 · Kiosko con QR y PIN
3. ASI-7 · Conectores abiertos a relojes biométricos
4. ASI-11 · Importación de asistencia
5. ASI-2 · Marcación web
6. ASI-3 · Marcación desde la app con selfie y geocerca
7. ASI-5 · Marcación de cuadrilla
8. ASI-6 · Marcación sin conexión
9. ASI-8 · Cálculo diario de asistencia
10. ASI-9 · Incidencias y regularización
11. ASI-10 · Tablero y reporte legal de asistencia
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-06, A-12, D-16. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ASI-1` | Registro de marcaciones inalterable | Tarea | — | HIS-1, AUD-1 |
| 2 | `ASI-4` | Kiosko con QR y PIN | Historia | — | ASI-1, SEG-2 |
| 3 | `ASI-7` | Conectores abiertos a relojes biométricos | Tarea | — | ASI-1, PLT-13 |
| 4 | `ASI-11` | Importación de asistencia | Historia | — | ASI-1, IMP-1 |
| 5 | `ASI-2` | Marcación web | Historia | — | ASI-1, ESS-1 |
| 6 | `ASI-3` | Marcación desde la app con selfie y geocerca | Historia | — | ASI-2, ESS-6, ORG-2 |
| 7 | `ASI-5` | Marcación de cuadrilla | Historia | — | ASI-3, SEG-10 |
| 8 | `ASI-6` | Marcación sin conexión | Tarea | — | ASI-3, PLT-8 |
| 9 | `ASI-8` | Cálculo diario de asistencia | Tarea | — | ASI-1, TUR-2, AUS-6 |
| 10 | `ASI-9` | Incidencias y regularización | Historia | — | ASI-8, WFL-2 |
| 11 | `ASI-10` | Tablero y reporte legal de asistencia | Historia | — | ASI-8, UI-11 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUD-1`, `AUS-6`, `ESS-1`, `ESS-6`, `HIS-1`, `IMP-1`, `ORG-2`, `PLT-8`, `PLT-13`, `SEG-2`, `SEG-10`, `TUR-2`, `UI-11`, `WFL-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-06 | Particionado de tablas grandes | Fase 2 | (a). Particionar después, con datos, es mucho más costoso; además facilita la retención (se descartan particiones). |
| A-12 | App móvil: web instalable o nativa | Fase 2 | (c). La API ya lo permite; la PWA cubre los flujos del colaborador. |
| D-16 | Biometría y reconocimiento facial en marcación | Fase 2 | (a) al inicio; (b) opcional por organización, con evaluación de impacto aprobada, consentimiento y método alternativo. Evitar (c) mientras no haya una necesidad clara. |
