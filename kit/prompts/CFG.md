# CFG · Configuración, campos personalizados, i18n y multimoneda

Centro de configuración, parámetros con vigencia, campos personalizados, idiomas, monedas, calendarios y papelera.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 10 (5 historias y 5 tareas); 10 del MVP. **Operaciones de API:** 29.
- **Especificación:** `kit/docs/modulos/M-CFG.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CFG · Configuración, campos personalizados, i18n y multimoneda de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CFG.md, kit/prompts/CFG.md y la sección CFG de
kit/backlog/backlog.md (grep -n "^### CFG-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CFG-6 · Internacionalización
2. CFG-3 · Parámetros con vigencia por fecha
3. CFG-7 · Multimoneda y tipos de cambio
4. CFG-10 · Catálogos maestros
5. CFG-9 · Papelera y restauración
6. CFG-1 · Centro de configuración
7. CFG-2 · Configuración por empresa
8. CFG-4 · Campos personalizados
9. CFG-5 · Campos personalizados en tablas, filtros, importación y API
10. CFG-8 · Calendarios, feriados y calendario de RRHH
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
| 1 | `CFG-6` | Internacionalización | Tarea | MVP | UI-1, PLT-4 |
| 2 | `CFG-3` | Parámetros con vigencia por fecha | Tarea | MVP | PLT-12, AUD-1 |
| 3 | `CFG-7` | Multimoneda y tipos de cambio | Tarea | MVP | CFG-3 |
| 4 | `CFG-10` | Catálogos maestros | Tarea | MVP | CFG-3 |
| 5 | `CFG-9` | Papelera y restauración | Historia | MVP | PLT-12, AUD-2 |
| 6 | `CFG-1` | Centro de configuración | Historia | MVP | UI-2, SEG-8 |
| 7 | `CFG-2` | Configuración por empresa | Historia | MVP | CFG-1, TEN-5 |
| 8 | `CFG-4` | Campos personalizados | Historia | MVP | CFG-1, SEG-9 |
| 9 | `CFG-5` | Campos personalizados en tablas, filtros, importación y API | Tarea | MVP | CFG-4, UI-4 |
| 10 | `CFG-8` | Calendarios, feriados y calendario de RRHH | Historia | MVP | CFG-2, UI-12 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUD-1`, `AUD-2`, `PLT-4`, `PLT-12`, `SEG-8`, `SEG-9`, `TEN-5`, `UI-1`, `UI-2`, `UI-4`, `UI-12`
