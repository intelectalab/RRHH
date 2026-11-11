# PAI · Paquetes de país

Estructura de paquetes de país para extender el producto a Latinoamérica sin tocar el núcleo.

- **Fase:** Fase 9 · Ecosistema y expansión. **Módulo comercial:** —.
- **Actividades:** 4 (1 historias y 3 tareas); 0 del MVP. **Operaciones de API:** 3.
- **Especificación:** `kit/docs/modulos/M-PAI.md`.
- **Ya avanzado en el kit:** Paquete Perú: parámetros y conceptos en `kit/database/catalogos/`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PAI · Paquetes de país de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PAI.md, kit/prompts/PAI.md y la sección PAI de
kit/backlog/backlog.md (grep -n "^### PAI-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PAI-1 · Contrato del paquete de país
2. PAI-2 · Conformidad del paquete Perú
3. PAI-3 · Paquete de un segundo país como prueba
4. PAI-4 · Grupos con empresas en varios países
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-27, D-37. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `PAI-1` | Contrato del paquete de país | Tarea | — | NOM-1, CUM-1 |
| 2 | `PAI-2` | Conformidad del paquete Perú | Tarea | — | PAI-1 |
| 3 | `PAI-3` | Paquete de un segundo país como prueba | Tarea | — | PAI-2 |
| 4 | `PAI-4` | Grupos con empresas en varios países | Historia | — | PAI-3, CFG-7 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-7`, `CUM-1`, `NOM-1`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-27 | Responsable y proceso de actualización de parámetros legales | Fase 3 | (c): parámetros legales nacionales mantenidos por la plataforma con un responsable nombrado, doble revisión, fuente normativa obligatoria y aviso a las organizaciones; parámetros de política propia mantenidos por la organización. Definir quién es el especialista que valida y mantiene los casos dorados. |
| D-37 | Plantillas de obligaciones de cumplimiento del paquete Perú | Fase 7 | (a). Queda pendiente: no hay hoy un catálogo de plantillas en el kit donde aplicarlo. |
