# ORG · Estructura organizacional

Empresas, sedes, unidades, centros de costo, catálogo de puestos y organigrama dinámico.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 7 (7 historias y 0 tareas); 7 del MVP. **Operaciones de API:** 32.
- **Especificación:** `kit/docs/modulos/M-ORG.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica ORG · Estructura organizacional de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-ORG.md, kit/prompts/ORG.md y la sección ORG de
kit/backlog/backlog.md (grep -n "^### ORG-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. ORG-1 · Empresas y razones sociales
2. ORG-2 · Sedes, sucursales y ubicaciones
3. ORG-3 · Árbol de unidades organizacionales
4. ORG-4 · Centros de costo
5. ORG-5 · Catálogo de puestos, cargos, niveles y categorías
6. ORG-6 · Organigrama visual dinámico
7. ORG-7 · Directorio de personas
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
| 1 | `ORG-1` | Empresas y razones sociales | Historia | MVP | TEN-5, CFG-2 |
| 2 | `ORG-2` | Sedes, sucursales y ubicaciones | Historia | MVP | ORG-1, CFG-8 |
| 3 | `ORG-3` | Árbol de unidades organizacionales | Historia | MVP | ORG-1 |
| 4 | `ORG-4` | Centros de costo | Historia | MVP | ORG-1 |
| 5 | `ORG-5` | Catálogo de puestos, cargos, niveles y categorías | Historia | MVP | ORG-1 |
| 6 | `ORG-6` | Organigrama visual dinámico | Historia | MVP | ORG-3, UI-12 |
| 7 | `ORG-7` | Directorio de personas | Historia | MVP | ORG-3, SEG-9 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-2`, `CFG-8`, `SEG-9`, `TEN-5`, `UI-12`
