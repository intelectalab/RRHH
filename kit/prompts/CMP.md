# CMP · Competencias y habilidades

Catálogo de competencias con niveles, perfiles por puesto, matriz de habilidades y brechas.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Desempeño y objetivos.
- **Actividades:** 4 (4 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 12.
- **Especificación:** `kit/docs/modulos/M-CMP.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica CMP · Competencias y habilidades de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-CMP.md, kit/prompts/CMP.md y la sección CMP de
kit/backlog/backlog.md (grep -n "^### CMP-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. CMP-1 · Catálogo de competencias y niveles
2. CMP-2 · Perfil de competencias por puesto
3. CMP-3 · Matriz de habilidades
4. CMP-4 · Brechas de competencias
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
| 1 | `CMP-1` | Catálogo de competencias y niveles | Historia | — | ORG-5 |
| 2 | `CMP-2` | Perfil de competencias por puesto | Historia | — | CMP-1 |
| 3 | `CMP-3` | Matriz de habilidades | Historia | — | CMP-1, COL-3 |
| 4 | `CMP-4` | Brechas de competencias | Historia | — | CMP-2, CMP-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-3`, `ORG-5`
