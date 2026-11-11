# UI · Sistema de diseño y armazón

Identidad visual propia, componentes reutilizables, armazón de navegación, tablas, formularios y patrones de experiencia para toda la aplicación.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 12 (3 historias y 9 tareas); 12 del MVP. **Operaciones de API:** 0.
- **Especificación:** `kit/docs/modulos/M-UI.md`.
- **Ya avanzado en el kit:** Tokens de diseño en `docs/10` y prototipo navegable en `kit/prototipo/`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica UI · Sistema de diseño y armazón de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-UI.md, kit/prompts/UI.md y la sección UI de
kit/backlog/backlog.md (grep -n "^### UI-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. UI-1 · Fundamentos visuales y temas claro y oscuro
2. UI-3 · Biblioteca de componentes
3. UI-5 · Formularios y validación en interfaz
4. UI-6 · Estados vacíos, carga y error
5. UI-10 · Divulgación progresiva
6. UI-12 · Componentes de calendario, kanban y gráficos
7. UI-4 · Tablas profesionales
8. UI-2 · Armazón de navegación
9. UI-11 · Servicio de exportación
10. UI-7 · Paleta de comandos y búsqueda global
11. UI-8 · Botón de creación rápida
12. UI-9 · Experiencia móvil y aplicación instalable
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
| 1 | `UI-1` | Fundamentos visuales y temas claro y oscuro | Tarea | MVP | PLT-1 |
| 2 | `UI-3` | Biblioteca de componentes | Tarea | MVP | UI-1 |
| 3 | `UI-5` | Formularios y validación en interfaz | Tarea | MVP | UI-3, PLT-4 |
| 4 | `UI-6` | Estados vacíos, carga y error | Tarea | MVP | UI-3 |
| 5 | `UI-10` | Divulgación progresiva | Tarea | MVP | UI-3 |
| 6 | `UI-12` | Componentes de calendario, kanban y gráficos | Tarea | MVP | UI-3 |
| 7 | `UI-4` | Tablas profesionales | Tarea | MVP | UI-3, PLT-11 |
| 8 | `UI-2` | Armazón de navegación | Historia | MVP | UI-1, SAS-5, SEG-8 |
| 9 | `UI-11` | Servicio de exportación | Tarea | MVP | UI-4, SEG-12, PLT-6 |
| 10 | `UI-7` | Paleta de comandos y búsqueda global | Historia | MVP | UI-2, SEG-10, TEN-6 |
| 11 | `UI-8` | Botón de creación rápida | Historia | MVP | UI-2 |
| 12 | `UI-9` | Experiencia móvil y aplicación instalable | Tarea | MVP | UI-2, UI-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`PLT-1`, `PLT-4`, `PLT-6`, `PLT-11`, `SAS-5`, `SEG-8`, `SEG-10`, `SEG-12`, `TEN-6`
