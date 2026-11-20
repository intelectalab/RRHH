# CPT · Conceptos y fórmulas

Catálogo de conceptos de nómina con fórmula, condiciones, vigencia, afectaciones y contabilización.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 7 (5 historias y 2 tareas); 7 del MVP. **Operaciones de API:** 16.
- **Especificación:** `kit/docs/modulos/M-CPT.md`.
- **Ya avanzado en el kit:** `kit/referencia/calculadora-planilla/`: líneas remunerativas y computables; 62 conceptos en `catalogos/conceptos_pe.json`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CPT · Conceptos y fórmulas de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CPT.md, kit/prompts/CPT.md y la sección CPT de
kit/backlog/backlog.md (grep -n "^### CPT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CPT-1 · Catálogo de conceptos
2. CPT-2 · Editor de fórmulas y condiciones
3. CPT-3 · Afectaciones de cada concepto
4. CPT-4 · Contabilización por concepto
5. CPT-6 · Importación de conceptos e incidencias
6. CPT-5 · Conceptos peruanos precargados
7. CPT-7 · Códigos de la tabla de conceptos y naturaleza en la proyección de quinta
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: ninguna asignada. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `CPT-1` | Catálogo de conceptos | Historia | MVP | NOM-3 |
| 2 | `CPT-2` | Editor de fórmulas y condiciones | Historia | MVP | CPT-1, NOM-4 |
| 3 | `CPT-3` | Afectaciones de cada concepto | Historia | MVP | CPT-1 |
| 4 | `CPT-4` | Contabilización por concepto | Historia | MVP | CPT-1, ORG-4 |
| 5 | `CPT-6` | Importación de conceptos e incidencias | Historia | MVP | CPT-1, IMP-1 |
| 6 | `CPT-5` | Conceptos peruanos precargados | Tarea | MVP | CPT-3 |
| 7 | `CPT-7` | Códigos de la tabla de conceptos y naturaleza en la proyección de quinta | Tarea | MVP | CPT-5 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`IMP-1`, `NOM-3`, `NOM-4`, `ORG-4`
