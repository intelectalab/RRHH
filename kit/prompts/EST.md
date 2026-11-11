# EST · Modo estudio contable

Consola de socio para que un estudio contable administre las planillas de muchas empresas pequeñas.

- **Fase:** Fase 9 · Ecosistema y expansión. **Módulo comercial:** Estudio contable.
- **Actividades:** 5 (5 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 4.
- **Especificación:** `kit/docs/modulos/M-EST.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica EST · Modo estudio contable de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-EST.md, kit/prompts/EST.md y la sección EST de
kit/backlog/backlog.md (grep -n "^### EST-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. EST-1 · Consola de socio
2. EST-2 · Alta de empresas cliente y delegación
3. EST-3 · Procesos de planilla en lote
4. EST-5 · Portal de la empresa cliente
5. EST-4 · Tablero de obligaciones por cliente
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-28. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `EST-1` | Consola de socio | Historia | ola 2 | TEN-1, SEG-10 |
| 2 | `EST-2` | Alta de empresas cliente y delegación | Historia | ola 2 | EST-1, IMP-4 |
| 3 | `EST-3` | Procesos de planilla en lote | Historia | ola 2 | EST-2, PER-8, DEC-2 |
| 4 | `EST-5` | Portal de la empresa cliente | Historia | ola 2 | EST-3 |
| 5 | `EST-4` | Tablero de obligaciones por cliente | Historia | ola 2 | EST-2, CUM-9 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CUM-9`, `DEC-2`, `IMP-4`, `PER-8`, `SEG-10`, `TEN-1`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-28 | Modo estudio contable: propiedad de los datos y permisos del socio | Fase 9 | (a). Mantiene el aislamiento y permite que el cliente conserve sus datos si cambia de estudio. Definir qué permisos tiene el socio por defecto y quién es titular de la suscripción. |
