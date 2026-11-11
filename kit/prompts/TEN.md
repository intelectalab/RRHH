# TEN · Multiempresa y aislamiento

Aislamiento total entre organizaciones con `tenant_id`, RLS y claves compuestas, y jerarquía organización → empresas.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 6 (1 historias y 5 tareas); 6 del MVP. **Operaciones de API:** 3.
- **Especificación:** `kit/docs/modulos/M-TEN.md`.
- **Ya avanzado en el kit:** `kit/database/pruebas_aislamiento.sql`: RLS, FK compuestas y tablas inalterables, comprobado en PostgreSQL 17.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica TEN · Multiempresa y aislamiento de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-TEN.md, kit/prompts/TEN.md y la sección TEN de
kit/backlog/backlog.md (grep -n "^### TEN-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. TEN-1 · Contexto de organización en cada petición
2. TEN-2 · Políticas RLS en PostgreSQL
3. TEN-5 · Organización con varias empresas y selector de empresa
4. TEN-6 · Aislamiento en caché, colas, archivos, búsqueda y exportaciones
5. TEN-3 · Claves foráneas compuestas por organización
6. TEN-4 · Prueba automática de aislamiento sobre todos los endpoints
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
| 1 | `TEN-1` | Contexto de organización en cada petición | Tarea | MVP | PLT-1, PLT-12 |
| 2 | `TEN-2` | Políticas RLS en PostgreSQL | Tarea | MVP | TEN-1 |
| 3 | `TEN-5` | Organización con varias empresas y selector de empresa | Historia | MVP | TEN-1 |
| 4 | `TEN-6` | Aislamiento en caché, colas, archivos, búsqueda y exportaciones | Tarea | MVP | TEN-1, PLT-6, PLT-7 |
| 5 | `TEN-3` | Claves foráneas compuestas por organización | Tarea | MVP | TEN-2 |
| 6 | `TEN-4` | Prueba automática de aislamiento sobre todos los endpoints | Tarea | MVP | TEN-2, PLT-5, PLT-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`PLT-1`, `PLT-3`, `PLT-5`, `PLT-6`, `PLT-7`, `PLT-12`
