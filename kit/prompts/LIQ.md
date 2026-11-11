# LIQ · Liquidación de beneficios sociales

Cálculo y simulación de la liquidación por cese y documentos de salida.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 4 (3 historias y 1 tareas); 4 del MVP. **Operaciones de API:** 9.
- **Especificación:** `kit/docs/modulos/M-LIQ.md`.
- **Ya avanzado en el kit:** `kit/referencia/calculadora-planilla/`: liquidación con truncos, indemnizaciones y quinta del cese.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica LIQ · Liquidación de beneficios sociales de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-LIQ.md, kit/prompts/LIQ.md y la sección LIQ de
kit/backlog/backlog.md (grep -n "^### LIQ-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. LIQ-1 · Cálculo de la liquidación
2. LIQ-2 · Simulador de liquidación
3. LIQ-3 · Aprobación, pago y plazo de la liquidación
4. LIQ-4 · Documentos de cese
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-15. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `LIQ-1` | Cálculo de la liquidación | Tarea | MVP | BSO-2, BSO-5, COL-11 |
| 2 | `LIQ-2` | Simulador de liquidación | Historia | MVP | LIQ-1 |
| 3 | `LIQ-3` | Aprobación, pago y plazo de la liquidación | Historia | MVP | LIQ-1, WFL-2 |
| 4 | `LIQ-4` | Documentos de cese | Historia | MVP | LIQ-3, DOC-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`BSO-2`, `BSO-5`, `COL-11`, `DOC-6`, `WFL-2`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-15 | Validación legal de plazos, retenciones y rol de encargado | Fase 1 (y antes de cada fase con norma) | (a). Cada valor entra como parámetro con estado «por validar» y no puede usarse en producción hasta que lo valide el responsable (D-27). |
