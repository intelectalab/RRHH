# QA · Calidad, seguridad y salida a producción

Pruebas de extremo a extremo por fase, pruebas doradas de planilla, rendimiento, seguridad, protección de datos, migración, ayuda y salida a producción.

- **Fase:** Transversal · Calidad. **Módulo comercial:** —.
- **Actividades:** 17 (0 historias y 17 tareas); 3 del MVP. **Operaciones de API:** 0.
- **Especificación:** `kit/docs/modulos/M-QA.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica QA · Calidad, seguridad y salida a producción de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-QA.md, kit/prompts/QA.md y la sección QA de
kit/backlog/backlog.md (grep -n "^### QA-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. QA-1 · Estrategia de pruebas y definición de hecho
2. QA-2 · Recorrido de extremo a extremo de cimientos y Core HR
3. QA-10 · Accesibilidad
4. QA-3 · Recorrido de extremo a extremo de tiempo
5. QA-9 · Pruebas de concurrencia
6. QA-8 · Recorrido de extremo a extremo de inteligencia y ecosistema
7. QA-12 · Revisión de seguridad
8. QA-17 · Salida a producción del primer corte comercial
9. QA-4 · Recorrido de extremo a extremo de nómina
10. QA-5 · Pruebas doradas de planilla validadas por especialista laboral
11. QA-6 · Recorrido de extremo a extremo de talento, desarrollo y compensación
12. QA-7 · Recorrido de extremo a extremo de experiencia, operación y cumplimiento
13. QA-11 · Pruebas de carga con 50 000 colaboradores
14. QA-13 · Verificación de protección de datos
15. QA-14 · Migración de los datos de una empresa
16. QA-15 · Material de ayuda
17. QA-16 · Salida a producción
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-06, A-13, D-22, D-24. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `QA-1` | Estrategia de pruebas y definición de hecho | Tarea | MVP | PLT-3, PLT-14 |
| 2 | `QA-2` | Recorrido de extremo a extremo de cimientos y Core HR | Tarea | MVP | QA-1, IMP-5, DOC-7, SAS-5, MSS-3 |
| 3 | `QA-10` | Accesibilidad | Tarea | — | QA-2, UI-9 |
| 4 | `QA-3` | Recorrido de extremo a extremo de tiempo | Tarea | — | QA-2, VAC-3, ASI-9, BHO-4, TSH-3 |
| 5 | `QA-9` | Pruebas de concurrencia | Tarea | — | QA-3, PER-8, WFL-2 |
| 6 | `QA-8` | Recorrido de extremo a extremo de inteligencia y ecosistema | Tarea | — | QA-2, IAR-8, API-5, WHA-5, EST-5 |
| 7 | `QA-12` | Revisión de seguridad | Tarea | — | QA-8, TEN-4 |
| 8 | `QA-17` | Salida a producción del primer corte comercial | Tarea | MVP | QA-2, PER-13, BOL-2, DEC-4, LIQ-3 |
| 9 | `QA-4` | Recorrido de extremo a extremo de nómina | Tarea | — | QA-3, DEC-7, PRV-2, LIQ-4, BOL-2 |
| 10 | `QA-5` | Pruebas doradas de planilla validadas por especialista laboral | Tarea | — | QA-4, BSO-3, PER-9, LOC-3, NOM-7 |
| 11 | `QA-6` | Recorrido de extremo a extremo de talento, desarrollo y compensación | Tarea | — | QA-2, OFF-5, CAR-7, COM-6, LMS-8 |
| 12 | `QA-7` | Recorrido de extremo a extremo de experiencia, operación y cumplimiento | Tarea | — | QA-2, ENC-7, HSD-7, DEN-7, CUM-7 |
| 13 | `QA-11` | Pruebas de carga con 50 000 colaboradores | Tarea | — | QA-4, ANA-3 |
| 14 | `QA-13` | Verificación de protección de datos | Tarea | — | QA-7, PRI-7, IAR-2 |
| 15 | `QA-14` | Migración de los datos de una empresa | Tarea | — | QA-5, IMP-6, VAC-7, ASI-11, PER-12 |
| 16 | `QA-15` | Material de ayuda | Tarea | — | QA-6, QA-7, QA-8, AYU-1 |
| 17 | `QA-16` | Salida a producción | Tarea | — | QA-9, QA-10, QA-11, QA-12, QA-13 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ANA-3`, `API-5`, `ASI-9`, `ASI-11`, `AYU-1`, `BHO-4`, `BOL-2`, `BSO-3`, `CAR-7`, `COM-6`, `CUM-7`, `DEC-4`, `DEC-7`, `DEN-7`, `DOC-7`, `ENC-7`, `EST-5`, `HSD-7`, `IAR-2`, `IAR-8`, `IMP-5`, `IMP-6`, `LIQ-3`, `LIQ-4`, `LMS-8`, `LOC-3`, `MSS-3`, `NOM-7`, `OFF-5`, `PER-8`, `PER-9`, `PER-12`, `PER-13`, `PLT-3`, `PLT-14`, `PRI-7`, `PRV-2`, `SAS-5`, `TEN-4`, `TSH-3`, `UI-9`, `VAC-3`, `VAC-7`, `WFL-2`, `WHA-5`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-06 | Particionado de tablas grandes | Fase 2 | (a). Particionar después, con datos, es mucho más costoso; además facilita la retención (se descartan particiones). |
| A-13 | Objetivos de recuperación y restauración por organización | Fase 0 (salida a producción) | recuperación a un punto en el tiempo + (b) mediante exportación e importación por `tenant_id` desde un entorno aislado. (c) se cubre con papelera e historia con vigencia. |
| D-22 | Presupuestos de rendimiento | Fase 1 | fijarlos como umbrales de la prueba de carga (`11` §11) antes de construir la fase 1, para que el diseño de índices y proyecciones se mida contra ellos. |
| D-24 | Certificaciones de seguridad objetivo | Fase 0 (salida a producción) | diseñar los controles desde la fase 0 conforme a `12` y decidir el calendario de certificación con el negocio. |
