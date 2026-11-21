# CUM · Cumplimiento Perú y SUNAFIL

Tablero de obligaciones laborales por empresa, pólizas, expediente de inspección y casilla electrónica.

- **Fase:** Fase 7 · Operación y cumplimiento. **Módulo comercial:** Cumplimiento Perú.
- **Actividades:** 9 (7 historias y 2 tareas); 1 del MVP. **Operaciones de API:** 31.
- **Especificación:** `kit/docs/modulos/M-CUM.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CUM · Cumplimiento Perú y SUNAFIL de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CUM.md, kit/prompts/CUM.md y la sección CUM de
kit/backlog/backlog.md (grep -n "^### CUM-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CUM-8 · Libro inalterable de cumplimiento encadenado por huella
2. CUM-1 · Catálogo de obligaciones laborales
3. CUM-4 · Pólizas de Vida Ley y SCTR
4. CUM-9 · Tablero mínimo de obligaciones de planilla
5. CUM-2 · Tablero de cumplimiento con semáforo
6. CUM-6 · Expediente de inspección SUNAFIL
7. CUM-3 · Responsables, evidencias y calendario
8. CUM-5 · Cuota de discapacidad y lactario
9. CUM-7 · Bandeja de casilla electrónica
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-34, D-37. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `CUM-8` | Libro inalterable de cumplimiento encadenado por huella | Tarea | — | AUD-1 |
| 2 | `CUM-1` | Catálogo de obligaciones laborales | Tarea | — | CFG-3, ORG-1 |
| 3 | `CUM-4` | Pólizas de Vida Ley y SCTR | Historia | — | CUM-1, COL-7 |
| 4 | `CUM-9` | Tablero mínimo de obligaciones de planilla | Historia | MVP | DEC-3, DEC-4, BSO-2, CON-4, NOT-2 |
| 5 | `CUM-2` | Tablero de cumplimiento con semáforo | Historia | — | CUM-1, CUM-9, SST-7, DEN-7, COM-7 |
| 6 | `CUM-6` | Expediente de inspección SUNAFIL | Historia | — | BOL-3, ASI-10, CON-2, SST-8 |
| 7 | `CUM-3` | Responsables, evidencias y calendario | Historia | — | CUM-2, CFG-8 |
| 8 | `CUM-5` | Cuota de discapacidad y lactario | Historia | — | CUM-2, COL-4 |
| 9 | `CUM-7` | Bandeja de casilla electrónica | Historia | — | CUM-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-10`, `AUD-1`, `BOL-3`, `BSO-2`, `CFG-3`, `CFG-8`, `COL-4`, `COL-7`, `COM-7`, `CON-2`, `CON-4`, `DEC-3`, `DEC-4`, `DEN-7`, `NOT-2`, `ORG-1`, `SST-7`, `SST-8`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-34 | Sello de tiempo externo y forma canónica del libro de cumplimiento | Fase 7 | RFC 8785 en la aplicación, ancla diaria y al exportar, proveedor detrás de un adaptador con implementación falsa. Sin proveedor, el libro prueba orden e integridad, no fecha cierta ante terceros. |
| D-37 | Plantillas de obligaciones de cumplimiento del paquete Perú | Fase 7 | (a). Queda pendiente: no hay hoy un catálogo de plantillas en el kit donde aplicarlo. |
