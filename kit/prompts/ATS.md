# ATS · Reclutamiento, portal de empleos y base de talento

Vacantes ligadas a posiciones, portal público, pipeline configurable, ficha del candidato y base de talento.

- **Fase:** Fase 4 · Talento. **Módulo comercial:** Reclutamiento.
- **Actividades:** 9 (9 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 30.
- **Especificación:** `kit/docs/modulos/M-ATS.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ATS · Reclutamiento, portal de empleos y base de talento de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ATS.md, kit/prompts/ATS.md y la sección ATS de
kit/backlog/backlog.md (grep -n "^### ATS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ATS-1 · Vacantes ligadas a posiciones
2. ATS-2 · Aprobación y publicación de la vacante
3. ATS-3 · Portal público de empleos
4. ATS-4 · Formulario de postulación configurable
5. ATS-5 · Pipeline configurable con arrastrar y soltar
6. ATS-6 · Ficha del candidato
7. ATS-8 · Referidos y movilidad interna
8. ATS-9 · Indicadores de reclutamiento
9. ATS-7 · Base de talento
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-05. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `ATS-1` | Vacantes ligadas a posiciones | Historia | — | POS-6, POS-2 |
| 2 | `ATS-2` | Aprobación y publicación de la vacante | Historia | — | ATS-1, WFL-2 |
| 3 | `ATS-3` | Portal público de empleos | Historia | — | ATS-2 |
| 4 | `ATS-4` | Formulario de postulación configurable | Historia | — | ATS-3 |
| 5 | `ATS-5` | Pipeline configurable con arrastrar y soltar | Historia | — | ATS-4, UI-12 |
| 6 | `ATS-6` | Ficha del candidato | Historia | — | ATS-5 |
| 7 | `ATS-8` | Referidos y movilidad interna | Historia | — | ATS-5, ESS-1 |
| 8 | `ATS-9` | Indicadores de reclutamiento | Historia | — | ATS-5 |
| 9 | `ATS-7` | Base de talento | Historia | — | ATS-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ESS-1`, `POS-2`, `POS-6`, `UI-12`, `WFL-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-05 | Política «no contratar sin posición aprobada» | Fase 1 | (b), apagada por defecto para organizaciones pequeñas; cuando está apagada, el alta crea la posición de forma implícita para que el organigrama por posiciones siga funcionando. |
