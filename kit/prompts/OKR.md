# OKR · Objetivos

Objetivos corporativos, de área e individuales con resultados clave y avance.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Desempeño y objetivos.
- **Actividades:** 5 (4 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 7.
- **Especificación:** `kit/docs/modulos/M-OKR.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica OKR · Objetivos de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-OKR.md, kit/prompts/OKR.md y la sección OKR de
kit/backlog/backlog.md (grep -n "^### OKR-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. OKR-1 · Ciclos y objetivos
2. OKR-2 · Resultados clave y avance
3. OKR-3 · Alineación jerárquica
4. OKR-5 · Objetivos en la evaluación de desempeño
5. OKR-4 · Seguimiento periódico y tablero
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
| 1 | `OKR-1` | Ciclos y objetivos | Historia | — | HIS-6, WFL-2 |
| 2 | `OKR-2` | Resultados clave y avance | Historia | — | OKR-1 |
| 3 | `OKR-3` | Alineación jerárquica | Historia | — | OKR-2, UI-12 |
| 4 | `OKR-5` | Objetivos en la evaluación de desempeño | Tarea | — | OKR-2, DES-2 |
| 5 | `OKR-4` | Seguimiento periódico y tablero | Historia | — | OKR-3, ESS-1 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`DES-2`, `ESS-1`, `HIS-6`, `UI-12`, `WFL-2`
