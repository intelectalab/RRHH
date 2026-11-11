# INT · Integraciones

Marco de conectores desacoplados para ERP, bancos, biométricos, SSO, calendario y otros.

- **Fase:** Fase 9 · Ecosistema y expansión. **Módulo comercial:** API e integraciones.
- **Actividades:** 7 (7 historias y 0 tareas); 0 del MVP. **Operaciones de API:** 9.
- **Especificación:** `kit/docs/modulos/M-INT.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica INT · Integraciones de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-INT.md, kit/prompts/INT.md y la sección INT de
kit/backlog/backlog.md (grep -n "^### INT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. INT-1 · Marco y catálogo de integraciones
2. INT-4 · Conectores de relojes biométricos
3. INT-5 · Inicio de sesión único (SSO)
4. INT-6 · Calendario y correo
5. INT-7 · Conectores de selección y aprendizaje externos
6. INT-3 · Conectores bancarios
7. INT-2 · Conector contable y de ERP
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-12. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `INT-1` | Marco y catálogo de integraciones | Historia | — | PLT-13, SEG-11 |
| 2 | `INT-4` | Conectores de relojes biométricos | Historia | — | INT-1, ASI-7 |
| 3 | `INT-5` | Inicio de sesión único (SSO) | Historia | — | INT-1, SEG-6 |
| 4 | `INT-6` | Calendario y correo | Historia | — | INT-1, ENT-1, FBK-1 |
| 5 | `INT-7` | Conectores de selección y aprendizaje externos | Historia | — | INT-1, ATS-6, LMS-6 |
| 6 | `INT-3` | Conectores bancarios | Historia | — | INT-1, DEC-5 |
| 7 | `INT-2` | Conector contable y de ERP | Historia | — | INT-1, PRV-3 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`ASI-7`, `ATS-6`, `DEC-5`, `ENT-1`, `FBK-1`, `LMS-6`, `PLT-13`, `PRV-3`, `SEG-6`, `SEG-11`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-12 | Bancos, billeteras y ERP del primer lanzamiento | Fase 3 | empezar por archivo (no por conexión en línea) para los bancos con más clientes del mercado objetivo y un formato contable genérico (CSV configurable). El negocio entrega la lista priorizada. |
