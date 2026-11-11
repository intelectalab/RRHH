# COM · Compensaciones, bandas, revisión salarial e igualdad salarial

Estructura de compensación, bandas, campañas de revisión con presupuesto e igualdad salarial.

- **Fase:** Fase 5 · Desarrollo y compensación. **Módulo comercial:** Compensaciones.
- **Actividades:** 8 (7 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 25.
- **Especificación:** `kit/docs/modulos/M-COM.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica COM · Compensaciones, bandas, revisión salarial e igualdad salarial de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-COM.md, kit/prompts/COM.md y la sección COM de
kit/backlog/backlog.md (grep -n "^### COM-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. COM-2 · Bandas salariales
2. COM-7 · Cuadro de categorías y política salarial
3. COM-1 · Estructura de compensación
4. COM-3 · Posición del trabajador en la banda
5. COM-4 · Campañas de revisión salarial
6. COM-8 · Reporte de brecha salarial
7. COM-5 · Control de presupuesto de la campaña
8. COM-6 · Aplicación de los aumentos aprobados
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-10. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `COM-2` | Bandas salariales | Historia | — | ORG-5, CFG-7 |
| 2 | `COM-7` | Cuadro de categorías y política salarial | Historia | — | COM-2, DOC-6 |
| 3 | `COM-1` | Estructura de compensación | Historia | — | HIS-3, PER-8 |
| 4 | `COM-3` | Posición del trabajador en la banda | Historia | — | COM-2, COM-1 |
| 5 | `COM-4` | Campañas de revisión salarial | Historia | — | COM-3, WFL-2, DES-6 |
| 6 | `COM-8` | Reporte de brecha salarial | Historia | — | COM-7, COM-3 |
| 7 | `COM-5` | Control de presupuesto de la campaña | Historia | — | COM-4 |
| 8 | `COM-6` | Aplicación de los aumentos aprobados | Tarea | — | COM-5, HIS-3, CON-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-7`, `CON-3`, `DES-6`, `DOC-6`, `HIS-3`, `ORG-5`, `PER-8`, `WFL-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-10 | Cifrado de campo para sueldos | Fase 1 | (b). La planilla, las provisiones y la analítica necesitan sumar en la base; el control efectivo es el permiso por campo y el registro de accesos. Documento de identidad, cuenta bancaria, salud y denuncias sí van cifrados a nivel de campo. |
