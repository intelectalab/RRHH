# NOM · Motor de nómina

Motor de cálculo independiente: reglas, paquete de país y régimen laboral, con trazabilidad y retroactivos.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 12 (3 historias y 9 tareas); 10 del MVP. **Operaciones de API:** 20.
- **Especificación:** `kit/docs/modulos/M-NOM.md`.
- **Ya avanzado en el kit:** `kit/referencia/calculadora-planilla/`: pasos configurables, mes, prorrateo y regímenes agrario y de construcción civil.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica NOM · Motor de nómina de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-NOM.md, kit/prompts/NOM.md y la sección NOM de
kit/backlog/backlog.md (grep -n "^### NOM-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. NOM-1 · Arquitectura del motor: núcleo, paquete de país y régimen
2. NOM-2 · Regímenes laborales por empresa y por trabajador
3. NOM-3 · Intérprete de fórmulas seguro
4. NOM-4 · Orden de cálculo y dependencias entre conceptos
5. NOM-9 · Estrategia por régimen laboral
6. NOM-5 · Contexto de cálculo por relación laboral
7. NOM-10 · Micro y pequeña empresa: acreditación, ventas y permanencia
8. NOM-11 · Construcción civil: obras, categorías y planilla semanal
9. NOM-12 · Régimen agrario
10. NOM-6 · Trazabilidad del cálculo
11. NOM-8 · Cálculo masivo en segundo plano
12. NOM-7 · Recálculo retroactivo
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
| 1 | `NOM-1` | Arquitectura del motor: núcleo, paquete de país y régimen | Tarea | MVP | CFG-3, HIS-6 |
| 2 | `NOM-2` | Regímenes laborales por empresa y por trabajador | Tarea | MVP | NOM-1, ORG-1 |
| 3 | `NOM-3` | Intérprete de fórmulas seguro | Tarea | MVP | NOM-1 |
| 4 | `NOM-4` | Orden de cálculo y dependencias entre conceptos | Tarea | MVP | NOM-3 |
| 5 | `NOM-9` | Estrategia por régimen laboral | Tarea | MVP | NOM-1, PAR-5 |
| 6 | `NOM-5` | Contexto de cálculo por relación laboral | Tarea | MVP | NOM-2, AUS-6 |
| 7 | `NOM-10` | Micro y pequeña empresa: acreditación, ventas y permanencia | Historia | MVP | NOM-9, ORG-1 |
| 8 | `NOM-11` | Construcción civil: obras, categorías y planilla semanal | Historia | — | NOM-9, PAR-6, PER-1 |
| 9 | `NOM-12` | Régimen agrario | Historia | — | NOM-9, PAR-5 |
| 10 | `NOM-6` | Trazabilidad del cálculo | Tarea | MVP | NOM-5 |
| 11 | `NOM-8` | Cálculo masivo en segundo plano | Tarea | MVP | NOM-5, PLT-6 |
| 12 | `NOM-7` | Recálculo retroactivo | Tarea | MVP | NOM-6, HIS-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUS-6`, `CFG-3`, `HIS-4`, `HIS-6`, `ORG-1`, `PAR-5`, `PAR-6`, `PER-1`, `PLT-6`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-10 | Cifrado de campo para sueldos | Fase 1 | (b). La planilla, las provisiones y la analítica necesitan sumar en la base; el control efectivo es el permiso por campo y el registro de accesos. Documento de identidad, cuenta bancaria, salud y denuncias sí van cifrados a nivel de campo. |
