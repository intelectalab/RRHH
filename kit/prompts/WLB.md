# WLB · Marca blanca

Personalización de marca por organización según plan.

- **Fase:** Fase 9 · Ecosistema y expansión. **Módulo comercial:** Marca blanca.
- **Actividades:** 4 (3 historias y 1 tareas); 0 del MVP. **Operaciones de API:** 2.
- **Especificación:** `kit/docs/modulos/M-WLB.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica WLB · Marca blanca de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-WLB.md, kit/prompts/WLB.md y la sección WLB de
kit/backlog/backlog.md (grep -n "^### WLB-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. WLB-1 · Marca de la organización
2. WLB-2 · Dominio personalizado
3. WLB-3 · Correos con la marca del cliente
4. WLB-4 · Marca blanca según plan
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-40. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `WLB-1` | Marca de la organización | Historia | — | UI-1, SAS-5 |
| 2 | `WLB-2` | Dominio personalizado | Historia | — | WLB-1 |
| 3 | `WLB-3` | Correos con la marca del cliente | Historia | — | WLB-1, NOT-3 |
| 4 | `WLB-4` | Marca blanca según plan | Tarea | — | WLB-1, SAS-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`NOT-3`, `SAS-3`, `SAS-5`, `UI-1`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-40 | Dominio propio por organización (WLB-2) en el lanzamiento | Antes de abrir la venta | (a), salvo que ventas lo pida como condición. El subdominio cubre el lanzamiento y WLB-2 exige verificación de dominio y certificados por organización. |
