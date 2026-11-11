# DES · Evaluación de desempeño

Campañas de 90° a 360° con formularios, escalas, calibración y resultados.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Desempeño y objetivos.
- **Actividades:** 6 (6 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 13.
- **Especificación:** `kit/docs/modulos/M-DES.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica DES · Evaluación de desempeño de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-DES.md, kit/prompts/DES.md y la sección DES de
kit/backlog/backlog.md (grep -n "^### DES-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. DES-1 · Configuración de campañas
2. DES-2 · Formularios, preguntas y escalas
3. DES-3 · Red de evaluadores
4. DES-4 · Ejecución de la evaluación
5. DES-5 · Calibración
6. DES-6 · Resultados y devolución
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
| 1 | `DES-1` | Configuración de campañas | Historia | — | HIS-6, WFL-1 |
| 2 | `DES-2` | Formularios, preguntas y escalas | Historia | — | DES-1, CMP-2 |
| 3 | `DES-3` | Red de evaluadores | Historia | — | DES-1 |
| 4 | `DES-4` | Ejecución de la evaluación | Historia | — | DES-2, DES-3, WFL-9 |
| 5 | `DES-5` | Calibración | Historia | — | DES-4 |
| 6 | `DES-6` | Resultados y devolución | Historia | — | DES-5 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CMP-2`, `HIS-6`, `WFL-1`, `WFL-9`
